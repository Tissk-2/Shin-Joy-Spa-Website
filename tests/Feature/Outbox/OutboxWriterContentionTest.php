<?php

namespace Tests\Feature\Outbox;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Outbox\IsolatedOutboxDatabase;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/outbox/IsolatedOutboxDatabase.php';

class OutboxWriterContentionTest extends TestCase
{
    public function test_two_real_writers_recording_the_same_key_produce_one_message_and_delivery(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'outbox-a4a-'.bin2hex(random_bytes(12));
        mkdir($directory);
        touch($directory.'/database.sqlite');
        $workers = [];

        try {
            IsolatedOutboxDatabase::connect($directory);
            Artisan::call('migrate', ['--force' => true]);
            $this->assertSame(0, DB::transactionLevel());

            foreach (['first', 'second'] as $role) {
                $process = new Process(
                    [PHP_BINARY, base_path('tests/fixtures/outbox/record-worker.php'), $directory, $role],
                    base_path(),
                    null,
                    null,
                    20,
                );
                $process->start();
                $workers[$role] = $process;
                IsolatedOutboxDatabase::wait($directory.'/'.$role.'-ready');
            }

            touch($directory.'/first-go');
            IsolatedOutboxDatabase::wait($directory.'/first-recorded');
            touch($directory.'/second-go');
            IsolatedOutboxDatabase::wait($directory.'/second-attempting');

            // The first writer still holds the write lock with its message uncommitted;
            // the second insert is blocked and must not have produced anything yet.
            usleep(150000);
            $this->assertTrue($workers['second']->isRunning());
            $this->assertFileDoesNotExist($directory.'/second-recorded');
            $this->assertSame(0, DB::table('outbox_messages')->count());

            touch($directory.'/release-first');
            IsolatedOutboxDatabase::wait($directory.'/second-recorded');

            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            }

            $firstId = trim($workers['first']->getOutput());
            $secondId = trim($workers['second']->getOutput());
            $this->assertMatchesRegularExpression('/^\d+$/', $firstId);
            $this->assertSame($firstId, $secondId);

            $this->assertSame(1, DB::table('outbox_messages')->count());
            $this->assertSame(1, DB::table('outbox_deliveries')->count());

            $message = DB::table('outbox_messages')->first();
            $this->assertSame('{"booking_id":42,"note":"same payload"}', $message->payload);
            $this->assertSame(1, (int) $message->schema_version);

            $delivery = DB::table('outbox_deliveries')->first();
            $this->assertSame($message->id, $delivery->message_id);
            $this->assertSame('primary', $delivery->handler_key);
            $this->assertSame('pending', $delivery->state);
            $this->assertSame(0, (int) $delivery->attempts);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::disconnect('sqlite');
            foreach (glob($directory.'/*') ?: [] as $file) {
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
