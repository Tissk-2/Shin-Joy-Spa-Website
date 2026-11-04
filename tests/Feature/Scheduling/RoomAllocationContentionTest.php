<?php

namespace Tests\Feature\Scheduling;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Scheduling\IsolatedScheduleDatabase;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/scheduling/IsolatedScheduleDatabase.php';

class RoomAllocationContentionTest extends TestCase
{
    public function test_two_real_allocators_contend_for_last_room(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'scheduling-b3b-'.bin2hex(random_bytes(12));
        mkdir($directory);
        touch($directory.'/database.sqlite');
        $workers = [];
        try {
            IsolatedScheduleDatabase::connect($directory);
            Artisan::call('migrate', ['--force' => true]);
            $fixture = new RoomAllocationTest('fixture');
            [$first] = $fixture->fixture();
            $second = $first->replicate(['reference', 'submission_key']);
            $second->reference = 'contender';
            $second->submission_key = 'contender';
            $second->save();
            $this->assertSame(0, DB::transactionLevel());
            foreach (['first' => $first, 'second' => $second] as $role => $booking) {
                $process = new Process([PHP_BINARY, base_path('tests/fixtures/scheduling/allocation-worker.php'), $directory, $role, (string) $booking->id], base_path(), null, null, 20);
                $process->start();
                $workers[$role] = $process;
                IsolatedScheduleDatabase::wait($directory.'/'.$role.'-ready');
            }
            touch($directory.'/first-go');
            IsolatedScheduleDatabase::wait($directory.'/first-acquired');
            touch($directory.'/second-go');
            IsolatedScheduleDatabase::wait($directory.'/second-attempting');
            // The first process retains the write lock while the second UPDATE
            // is executing. It cannot signal acquisition before explicit release.
            usleep(150000);
            $this->assertTrue($workers['second']->isRunning());
            $this->assertFileDoesNotExist($directory.'/second-acquired');
            touch($directory.'/release-first');
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            }
            $this->assertSame('allocated', $workers['first']->getOutput());
            $this->assertSame('unavailable', $workers['second']->getOutput());
            $this->assertFileExists($directory.'/second-acquired');
            $this->assertSame(1, DB::table('room_reservations')->count());
            $row = DB::table('room_reservations')->first();
            $this->assertSame($first->id, $row->booking_id);
            $this->assertSame('2026-10-25 03:00:00', $row->starts_at);
            $this->assertSame('2026-10-25 04:45:00', $row->ends_at);
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
            // This test uses a separate committed file, so RefreshDatabase must
            // rebuild its memory database in the following test class.
            RefreshDatabaseState::$migrated = false;
        }
    }
}
