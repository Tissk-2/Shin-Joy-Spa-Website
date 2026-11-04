<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Staff\IsolatedStaffDatabase;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/staff/IsolatedStaffDatabase.php';

class StaffPermissionContentionTest extends TestCase
{
    public static function orderings(): array
    {
        return [
            'duplicate revokes' => ['revoke', 'revoke', 'revoke', 'pending', false, [[true, false]]],
            'opposing writes' => ['revoke', 'grant', 'grant', 'pending', true, [[true, false], [false, true]]],
            'revocation before stale mutation' => ['revoke', 'transition', 'denied', 'pending', false, [[true, false]]],
            'earlier authorized mutation' => ['transition', 'revoke', 'revoke', 'confirmed', false, [[true, false]]],
        ];
    }

    #[DataProvider('orderings')]
    public function test_real_processes_serialize_permission_and_status_writes(string $first, string $second, string $secondResult, string $status, bool $allowed, array $history): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'staff-b1a-'.bin2hex(random_bytes(12));
        mkdir($directory);
        touch($directory.'/database.sqlite');
        $workers = [];
        try {
            IsolatedStaffDatabase::connect($directory);
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $owner = User::factory()->owner()->create();
            $staff = User::factory()->bookingStaff()->create();
            $booking = Booking::factory()->create();
            $this->assertSame(0, DB::transactionLevel());
            foreach (['first' => $first, 'second' => $second] as $slot => $operation) {
                $worker = new Process([PHP_BINARY, base_path('tests/fixtures/staff/permission-worker.php'), $directory, $slot, $operation,
                    (string) $owner->id, (string) $staff->id, (string) $booking->id], base_path(), null, null, 30);
                $worker->start();
                $workers[$slot] = $worker;
                IsolatedStaffDatabase::wait($directory.'/'.$slot.'-ready');
            }
            touch($directory.'/first-go');
            IsolatedStaffDatabase::wait($directory.'/first-acquired');
            touch($directory.'/second-go');
            IsolatedStaffDatabase::wait($directory.'/second-attempting');
            usleep(150000);
            $this->assertTrue($workers['second']->isRunning());
            $this->assertFileDoesNotExist($directory.'/second-acquired');
            touch($directory.'/release-first');
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput().$worker->getOutput());
            }
            $this->assertSame($first === 'transition' ? 'confirmed' : $first, $workers['first']->getOutput());
            $this->assertSame($secondResult, $workers['second']->getOutput());
            $this->assertFileExists($directory.'/second-acquired');
            $this->assertSame($status, $booking->fresh()->status->value);
            $this->assertSame($allowed, $staff->fresh()->can_manage_bookings);
            $events = DB::table('staff_permission_changes')->orderBy('id')->get();
            $this->assertCount(count($history), $events);
            foreach ($history as $index => [$before, $after]) {
                $this->assertSame($before, (bool) $events[$index]->was_allowed);
                $this->assertSame($after, (bool) $events[$index]->is_allowed);
                $this->assertSame($owner->id, $events[$index]->actor_user_id);
            }
            $this->assertSame($status === 'confirmed' ? 1 : 0, DB::table('booking_status_changes')->count());
            $this->assertDatabaseCount('room_reservations', 0);
        } catch (\Throwable $exception) {
            $diagnostics = array_map(fn ($worker) => $worker->getErrorOutput().$worker->getOutput(), $workers);
            throw new \RuntimeException($exception->getMessage().' '.implode(' ', $diagnostics), 0, $exception);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::disconnect('sqlite');
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            config(['database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
            RefreshDatabaseState::$migrated = false;
        }
    }
}
