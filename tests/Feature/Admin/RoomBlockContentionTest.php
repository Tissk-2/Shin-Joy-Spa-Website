<?php

namespace Tests\Feature\Admin;

use App\Models\RoomBlock;
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

class RoomBlockContentionTest extends TestCase
{
    public static function races(): array
    {
        return [
            'approval then block' => ['approve', 'create', 'confirmed', 'conflict'],
            'block then approval' => ['create', 'approve', 'saved', 'unavailable'],
            'same version edits' => ['edit', 'edit', 'saved', 'stale'],
            'overlapping creates' => ['create', 'create', 'saved', 'conflict'],
            'guard wait crosses start' => ['later', 'cross-start', 'saved', 'active'],
        ];
    }

    #[DataProvider('races')]
    public function test_real_block_actions_serialize_conflicting_schedule_changes(string $first, string $second, string $firstResult, string $secondResult): void
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
            $original = $booking->getRawOriginal();
            $block = in_array($first, ['edit', 'later'], true) ? RoomBlock::create(['room_id' => $room->id, 'starts_at' => '2026-10-25 03:00:00', 'ends_at' => '2026-10-25 04:00:00', 'reason' => 'Original']) : null;
            foreach (['first' => $first, 'second' => $second] as $role => $operation) {
                $worker = new Process([PHP_BINARY, base_path('tests/fixtures/scheduling/room-block-worker.php'), $directory, $role, $operation, (string) $room->id, (string) $booking->id, (string) ($block?->id ?? 0)], base_path(), null, null, 30);
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
            $confirmed = $first === 'approve';
            $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => $confirmed ? 'confirmed' : 'pending']);
            $this->assertDatabaseCount('room_reservations', $confirmed ? 1 : 0);
            $this->assertDatabaseCount('room_blocks', $first === 'approve' ? 0 : ($first === 'later' ? 2 : 1));
            foreach (['service_id', 'service_variant_id', 'appointment_date', 'appointment_time', 'total_duration_minutes', 'guests'] as $key) {
                $this->assertSame($original[$key], $booking->fresh()->getRawOriginal($key));
            }
            if ($first === 'edit') {
                $this->assertDatabaseHas('room_blocks', ['id' => $block->id, 'version' => 2, 'reason' => 'Saved first']);
            }
            if ($first === 'later') {
                $this->assertDatabaseHas('room_blocks', ['id' => $block->id, 'version' => 1, 'starts_at' => '2026-10-25 03:00:00']);
            }
            if ($first === 'create') {
                $this->assertDatabaseHas('room_blocks', ['room_id' => $room->id, 'starts_at' => '2026-10-25 03:00:00', 'ends_at' => '2026-10-25 05:00:00']);
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
            } rmdir($directory);
            config(['database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
            RefreshDatabaseState::$migrated = false;
        }
    }
}
