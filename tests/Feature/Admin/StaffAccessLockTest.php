<?php

namespace Tests\Feature\Admin;

use App\Support\Staff\StaffAccessLock;
use Illuminate\Database\DeadlockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class StaffAccessLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_alone_does_not_own_guard(): void
    {
        $this->expectException(LogicException::class);
        (new StaffAccessLock)->assertHeld();
    }

    public function test_nested_instances_share_guard_and_rollback_inner_work(): void
    {
        $lock = new StaffAccessLock;
        $result = $lock->run(function () {
            (new StaffAccessLock)->assertHeld();
            try {
                (new StaffAccessLock)->run(function () {
                    DB::table('staff_access_guards')->update(['revision' => 99]);
                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException) {
            }
            $this->assertSame(1, DB::table('staff_access_guards')->value('revision'));

            return 42;
        });
        $this->assertSame(42, $result);
        $this->expectException(LogicException::class);
        $lock->assertHeld();
    }

    public function test_missing_guard_fails_closed(): void
    {
        DB::table('staff_access_guards')->delete();
        $this->expectException(LogicException::class);
        (new StaffAccessLock)->run(fn () => $this->fail('Business work ran'));
    }

    public function test_failed_operation_clears_ownership(): void
    {
        $lock = new StaffAccessLock;
        try {
            $lock->run(fn () => throw new RuntimeException('failed'));
            $this->fail('Domain failure was swallowed');
        } catch (RuntimeException $exception) {
            $this->assertSame('failed', $exception->getMessage());
        }
        $this->expectException(LogicException::class);
        $lock->assertHeld();
    }

    public function test_outermost_retries_only_recognized_concurrency_errors_and_is_bounded(): void
    {
        DB::rollBack(); // Leave RefreshDatabase's wrapper to exercise a real outer transaction.
        $attempts = 0;
        $result = (new StaffAccessLock)->run(function () use (&$attempts) {
            if (++$attempts < 3) {
                throw new \PDOException('database is locked');
            }

            return 'ok';
        });
        $this->assertSame('ok', $result);
        $this->assertSame(3, $attempts);
        $this->assertSame(1, DB::table('staff_access_guards')->value('revision'));
        $attempts = 0;
        try {
            (new StaffAccessLock)->run(function () use (&$attempts) {
                $attempts++;
                throw new \PDOException('database is locked');
            });
            $this->fail('Exhausted concurrency failure was swallowed');
        } catch (\PDOException $exception) {
            $this->assertSame('database is locked', $exception->getMessage());
            $this->assertSame(3, $attempts);
        }
        $attempts = 0;
        try {
            (new StaffAccessLock)->run(function () use (&$attempts) {
                $attempts++;
                throw new RuntimeException('domain failure');
            });
            $this->fail('Non-transient domain failure was swallowed');
        } catch (RuntimeException $exception) {
            $this->assertSame('domain failure', $exception->getMessage());
            $this->assertSame(1, $attempts);
        }
    }

    public function test_unrelated_outer_transaction_does_not_retry(): void
    {
        $attempts = 0;
        try {
            (new StaffAccessLock)->run(function () use (&$attempts) {
                $attempts++;
                throw new \PDOException('database is locked');
            });
            $this->fail('Outer transaction concurrency failure was swallowed');
        } catch (DeadlockException $exception) {
            $this->assertSame('database is locked', $exception->getMessage());
            $this->assertSame(1, $attempts);
        }
    }

    public function test_callback_manual_transaction_management_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        (new StaffAccessLock)->run(fn () => DB::rollBack());
    }

    public function test_switching_default_connection_is_rejected_and_cleans_ownership(): void
    {
        config(['database.connections.other' => config('database.connections.sqlite')]);
        $lock = new StaffAccessLock;
        try {
            $lock->run(function () {
                DB::setDefaultConnection('other');
            });
            $this->fail('Connection switch accepted');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::setDefaultConnection('sqlite');
        }
        $this->expectException(LogicException::class);
        $lock->assertHeld();
    }
}
