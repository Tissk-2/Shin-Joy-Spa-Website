<?php

namespace Tests\Concurrency;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Scheduling\RoomAllocationTest;

class ScheduleRaceTest extends ConcurrencyTestCase
{
    private const MIGRATION = 'database/migrations/2026_11_05_010011_support_manual_booking_identity.php';

    public function test_two_approvals_contend_for_the_last_room(): void
    {
        $fixture = new RoomAllocationTest('fixture');
        [$first] = $fixture->fixture();
        $second = $first->replicate(['reference', 'submission_key']);
        $second->reference = 'contender';
        $second->submission_key = 'contender';
        $second->save();
        $this->assertSame(0, DB::transactionLevel());
        User::factory()->bookingStaff()->count(2)->create();

        $this->worker('approve', 'first', $first->id);
        $this->worker('approve', 'second', $second->id);
        file_put_contents($this->directory.'/first-go', 'go');
        ConcurrencyDatabase::wait($this->directory.'/first-acquired', $this->workers['first']);
        file_put_contents($this->directory.'/second-go', 'go');
        ConcurrencyDatabase::wait($this->directory.'/second-attempting', $this->workers['second']);
        // The first worker retains the write lock while the second UPDATE
        // executes; it cannot signal acquisition before the explicit release.
        usleep(150000);
        $this->assertTrue($this->workers['second']->isRunning());
        $this->assertFileDoesNotExist($this->directory.'/second-acquired');
        file_put_contents($this->directory.'/release-first', 'release');

        foreach ($this->workers as $worker) {
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
        }

        $this->assertSame('confirmed', $this->workers['first']->getOutput());
        $this->assertSame('unavailable', $this->workers['second']->getOutput());
        $this->assertSame(1, DB::table('room_reservations')->count());
        $this->assertSame(1, DB::table('bookings')->where('status', 'confirmed')->count());
        $this->assertSame(1, DB::table('bookings')->where('status', 'pending')->count());
        $this->assertSame(1, DB::table('booking_status_changes')->count());
        $row = DB::table('room_reservations')->first();
        $this->assertSame($first->id, $row->booking_id);
        $this->assertSame('2026-10-25 03:00:00', substr($row->starts_at, 0, 19));
        $this->assertSame('2026-10-25 04:45:00', substr($row->ends_at, 0, 19));
    }

    public static function blockRaces(): array
    {
        return [
            'approval then block' => ['approve', 'block', 'confirmed', 'conflict', 'confirmed', 1, 0],
            'block then approval' => ['block', 'approve', 'saved', 'unavailable', 'pending', 0, 1],
        ];
    }

    #[DataProvider('blockRaces')]
    public function test_approval_and_a_maintenance_block_never_both_hold_the_room(
        string $first,
        string $second,
        string $firstOut,
        string $secondOut,
        string $status,
        int $reservations,
        int $blocks,
    ): void {
        [$booking] = (new RoomAllocationTest('fixture'))->fixture();
        User::factory()->owner()->create();
        User::factory()->bookingStaff()->count(2)->create();

        [$firstOutput, $secondOutput] = $this->race($first, $second, $booking->id);

        $this->assertSame($firstOut, $firstOutput);
        $this->assertSame($secondOut, $secondOutput);
        $this->assertSame($status, DB::table('bookings')->where('id', $booking->id)->value('status'));
        $this->assertSame($reservations, DB::table('room_reservations')->count());
        $this->assertSame($blocks, DB::table('room_blocks')->count());
        $this->assertSame(1, $reservations + $blocks);
    }

    public static function outcomeRaces(): array
    {
        return [
            'completion then no-show' => ['complete', 'no_show', 'completed', 'completed'],
            'no-show then completion' => ['no_show', 'complete', 'no_show', 'no_show'],
        ];
    }

    #[DataProvider('outcomeRaces')]
    public function test_completion_and_no_show_are_mutually_exclusive(string $first, string $second, string $firstOut, string $status): void
    {
        [$booking] = (new RoomAllocationTest('fixture'))->fixture();
        $booking->forceFill(['status' => 'confirmed', 'approved_at' => '2026-10-24 00:00:00'])->save();
        User::factory()->bookingStaff()->count(2)->create();

        [$firstOutput, $secondOutput] = $this->race($first, $second, $booking->id);

        $this->assertSame($firstOut, $firstOutput);
        $this->assertSame('stale', $secondOutput);
        $row = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame($status, $row->status);
        $changes = DB::table('booking_status_changes')->where('booking_id', $booking->id)->get();
        $this->assertCount(1, $changes);
        $this->assertSame($status, $changes->first()->to_status);

        if ($status === 'no_show') {
            $this->assertNotNull($row->refund_eligible_at);
            $this->assertSame('no_show', $row->refund_reason);
        } else {
            $this->assertNull($row->refund_eligible_at);
        }
    }

    public function test_migrations_round_trip_and_keep_the_staff_submission_index_order(): void
    {
        $index = fn () => collect(Schema::connection(ConcurrencyDatabase::CONNECTION)->getIndexes('bookings'))
            ->firstWhere('name', 'bookings_staff_submission_unique');

        $this->assertSame(['submission_key', 'created_by_staff_id'], $index()['columns']);
        $this->assertTrue($index()['unique']);

        $this->assertSame(0, Artisan::call('migrate:rollback', [
            '--force' => true,
            '--database' => ConcurrencyDatabase::CONNECTION,
            '--path' => self::MIGRATION,
        ]));
        $this->assertNull($index());

        $this->assertSame(0, Artisan::call('migrate', [
            '--force' => true,
            '--database' => ConcurrencyDatabase::CONNECTION,
            '--path' => self::MIGRATION,
        ]));
        $this->assertSame(['submission_key', 'created_by_staff_id'], $index()['columns']);
        $this->assertTrue($index()['unique']);
    }

    // Review addition: a worker that dies (bad credentials, refused connection, guard refusal) must fail
    // the test at once with its own error, not after a silent 15-second barrier timeout.
    public function test_a_crashing_worker_fails_fast_with_its_error(): void
    {
        $started = microtime(true);

        try {
            $this->worker('bogus', 'first', 1);
            $this->fail('A crashing worker was waited on as if it were ready.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Invalid worker arguments.', $exception->getMessage());
        }

        $this->assertLessThan(10, microtime(true) - $started);
    }

    public function test_the_mysql_session_runs_in_utc(): void
    {
        if ($this->driver() === 'sqlite') {
            $this->markTestSkipped('SQLite has no session time zone; run this suite with CONCURRENCY_DB_DRIVER=mysql.');
        }

        $this->assertSame('+00:00', DB::connection(ConcurrencyDatabase::CONNECTION)->selectOne('select @@session.time_zone as tz')->tz);
    }

    private function race(string $first, string $second, int $bookingId): array
    {
        $this->worker($first, 'first', $bookingId);
        $this->worker($second, 'second', $bookingId);
        file_put_contents($this->directory.'/first-go', 'go');
        ConcurrencyDatabase::wait($this->directory.'/first-acquired', $this->workers['first']);
        file_put_contents($this->directory.'/second-go', 'go');
        ConcurrencyDatabase::wait($this->directory.'/second-attempting', $this->workers['second']);
        usleep(150000);
        $this->assertTrue($this->workers['second']->isRunning());
        $this->assertFileDoesNotExist($this->directory.'/second-acquired');
        file_put_contents($this->directory.'/release-first', 'release');

        foreach ($this->workers as $worker) {
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
        }

        $this->assertFileExists($this->directory.'/second-acquired');

        return [$this->workers['first']->getOutput(), $this->workers['second']->getOutput()];
    }
}
