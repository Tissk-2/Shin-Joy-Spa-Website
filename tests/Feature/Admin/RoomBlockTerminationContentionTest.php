<?php

namespace Tests\Feature\Admin;

use App\Models\RoomBlock;
use App\Models\User;
use App\Support\Rooms\RoomBlockInterval;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Feature\Scheduling\RoomAllocationTest;
use Tests\Fixtures\Scheduling\IsolatedScheduleDatabase;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/scheduling/IsolatedScheduleDatabase.php';

class RoomBlockTerminationContentionTest extends TestCase
{
    public static function races(): array
    {
        return [
            'cancel versus edit' => ['cancel', 'edit', 'terminated', 'terminal'],
            'edit versus cancel' => ['edit', 'cancel', 'stale', 'edited'],
            'end versus edit' => ['end', 'edit-active', 'terminated', 'terminal'],
            'duplicate cancellation' => ['cancel', 'cancel', 'terminated', 'terminal'],
            'approval before cancellation' => ['approve', 'cancel', 'cancelled', 'terminal'],
            'approval after cancellation' => ['cancel', 'approve', 'confirmed', 'confirmed'],
            'wait crossing start' => ['hold', 'cross-start', 'lifecycle', 'unchanged'],
            'wait crossing end' => ['hold', 'cross-end', 'lifecycle', 'unchanged'],
            'revoked during wait' => ['revoke', 'cancel', 'forbidden', 'unchanged'],
            'after-guard timestamp' => ['hold', 'cross-time', 'ended', 'terminal'],
        ];
    }

    #[DataProvider('races')]
    public function test_real_actions_and_wait_boundaries(string $first, string $second, string $expected, string $state): void
    {
        $this->assertFileExists(base_path('tests/fixtures/scheduling/room-block-termination-worker.php'));
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'scheduling-b3b-'.bin2hex(random_bytes(12));
        mkdir($directory);
        touch($directory.'/database.sqlite');
        $workers = [];
        try {
            IsolatedScheduleDatabase::connect($directory);
            Artisan::call('migrate', ['--force' => true]);
            [$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
            User::factory()->owner()->create();
            $staff = User::factory()->admin()->create(['can_manage_bookings' => true]);
            $block = RoomBlock::create(['room_id' => $room->id, 'starts_at' => '2026-10-25 03:00:00', 'ends_at' => '2026-10-25 04:00:00', 'reason' => 'Original', 'created_by' => $staff->id]);
            $original = $block->getRawOriginal();
            $bookingBefore = $booking->fresh()->getRawOriginal();
            foreach (['first' => $first, 'second' => $second] as $role => $operation) {
                $worker = new Process([PHP_BINARY, base_path('tests/fixtures/scheduling/room-block-termination-worker.php'), $directory, $role, $operation, (string) $room->id, (string) $booking->id, (string) $block->id, (string) $staff->id], base_path(), null, null, 40);
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
            $this->assertSame($expected, $workers['second']->getOutput());
            $this->assertSame(match ($first) {
                'approve' => 'unavailable', 'cancel' => 'cancelled', 'end' => 'ended', 'edit' => 'saved', default => 'held'
            }, $workers['first']->getOutput());
            $this->assertPersisted($block, $original, $state, $second);
            $this->assertSame($bookingBefore, $state === 'confirmed' ? [...$booking->fresh()->getRawOriginal(), 'status' => $bookingBefore['status'], 'updated_at' => $bookingBefore['updated_at']] : $booking->fresh()->getRawOriginal());
            $this->assertDatabaseCount('room_reservations', $state === 'confirmed' ? 1 : 0);
            $this->assertDatabaseCount('booking_status_changes', $state === 'confirmed' ? 1 : 0);
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

    private function assertPersisted(RoomBlock $block, array $original, string $state, string $operation): void
    {
        $saved = $block->fresh();
        $terminal = DB::table('room_block_terminations')->where('room_block_id', $block->id)->first();
        $changed = in_array($state, ['terminal', 'confirmed', 'edited'], true);
        $this->assertSame($changed ? 2 : 1, $saved->version);
        $this->assertDatabaseCount('room_block_terminations', $terminal ? 1 : 0);
        $this->assertSame($state !== 'edited' && $changed, $terminal !== null);
        foreach (['starts_at', 'ends_at', 'room_id', 'created_by'] as $key) {
            $this->assertSame($original[$key], $saved->getRawOriginal($key));
        }
        $this->assertSame($state === 'edited' ? 'Saved first' : 'Original', $saved->reason);
        if ($terminal) {
            $this->assertSame(1, $terminal->previous_version);
            $this->assertSame(2, $terminal->resulting_version);
            if ($operation === 'cross-time') {
                $this->assertSame('2026-10-25 03:30:00.500000', $terminal->occurred_at);
            }
            $interval = new RoomBlockInterval;
            $start = CarbonImmutable::parse('2026-10-25 03:30:00.500000', 'UTC');
            $this->assertFalse($interval->overlaps($saved, $terminal, $start, $start->addHour()));
            $this->assertSame($terminal->kind === 'end_now', $interval->overlaps($saved, $terminal, $start->subSecond(), $start));
        }
    }
}
