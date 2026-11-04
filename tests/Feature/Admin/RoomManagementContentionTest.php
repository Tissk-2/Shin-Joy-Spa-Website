<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Feature\Scheduling\RoomAllocationTest;
use Tests\Fixtures\Scheduling\IsolatedScheduleDatabase;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/scheduling/IsolatedScheduleDatabase.php';

class RoomManagementContentionTest extends TestCase
{
    public static function races(): array
    {
        return [
            'edit vs edit' => ['edit', 'edit', 'saved', 'stale'],
            'approval then deactivation' => ['approve', 'deactivate', 'confirmed', 'conflict'],
            'deactivation then approval' => ['deactivate', 'approve', 'saved', 'unavailable'],
            'approval then compatibility removal' => ['approve', 'incompatible', 'confirmed', 'conflict'],
            'compatibility removal then approval' => ['incompatible', 'approve', 'saved', 'unavailable'],
        ];
    }

    #[DataProvider('races')]
    public function test_real_actions_serialize_owner_edits_and_booking_approval(string $first, string $second, string $firstResult, string $secondResult): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'scheduling-b3b-'.bin2hex(random_bytes(12));
        mkdir($directory);
        touch($directory.'/database.sqlite');
        $workers = [];
        try {
            IsolatedScheduleDatabase::connect($directory);
            Artisan::call('migrate', ['--force' => true]);
            [$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
            User::factory()->owner()->create();
            foreach (['first' => $first, 'second' => $second] as $role => $operation) {
                $worker = new Process([PHP_BINARY, base_path('tests/fixtures/scheduling/room-management-worker.php'), $directory, $role, $operation, (string) $room->id, (string) $booking->id], base_path(), null, null, 30);
                $worker->start();
                $workers[$role] = $worker;
                IsolatedScheduleDatabase::wait($directory.'/'.$role.'-ready');
            }
            touch($directory.'/first-go');
            IsolatedScheduleDatabase::wait($directory.'/first-acquired');
            touch($directory.'/second-go');
            IsolatedScheduleDatabase::wait($directory.'/second-attempting');
            usleep(150000);
            $this->assertTrue($workers['second']->isRunning());
            $this->assertFileDoesNotExist($directory.'/second-acquired');
            touch($directory.'/release-first');
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            }
            $this->assertSame($firstResult, $workers['first']->getOutput());
            $this->assertSame($secondResult, $workers['second']->getOutput());
            $this->assertFileExists($directory.'/second-acquired');
            $saved = DB::table('rooms')->first();
            $this->assertSame($first === 'approve' ? 1 : 2, $saved->version);
            if (DB::table('room_reservations')->count() === 1) {
                $this->assertTrue((bool) $saved->is_active);
                $this->assertGreaterThanOrEqual($booking->guests, $saved->capacity);
                $this->assertDatabaseHas('room_service_variant', ['room_id' => $room->id, 'service_variant_id' => $booking->service_variant_id]);
                $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
            } else {
                $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'pending']);
            }
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
