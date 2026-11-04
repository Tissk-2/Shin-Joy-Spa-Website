<?php

namespace Tests\Feature\Outbox;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Outbox\IsolatedOutboxDatabase;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/outbox/IsolatedOutboxDatabase.php';

class OutboxDispatcherContentionTest extends TestCase
{
    public function test_two_real_dispatchers_claim_each_delivery_exactly_once(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'outbox-a4a-'.bin2hex(random_bytes(12));
        mkdir($directory);
        touch($directory.'/database.sqlite');
        $workers = [];

        try {
            IsolatedOutboxDatabase::connect($directory);
            Artisan::call('migrate', ['--force' => true]);
            $this->assertSame(0, DB::transactionLevel());

            $now = now()->format('Y-m-d H:i:s');
            $messageId = DB::table('outbox_messages')->insertGetId([
                'type' => 'booking.created',
                'deduplication_key' => 'dispatcher-contention',
                'deduplication_hash' => hash('sha256', 'dispatcher-contention'),
                'payload' => '{"booking_id":7}',
                'schema_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $deliveryIds = [];

            for ($index = 0; $index < 40; $index++) {
                $key = 'worker-'.$index;
                $deliveryIds[] = DB::table('outbox_deliveries')->insertGetId([
                    'message_id' => $messageId,
                    'handler_key' => $key,
                    'handler_key_hash' => hash('sha256', $key),
                    'state' => 'pending',
                    'attempts' => 0,
                    'available_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach (['first', 'second'] as $role) {
                $process = new Process(
                    [PHP_BINARY, base_path('tests/fixtures/outbox/dispatch-worker.php'), $directory, $role, '40'],
                    base_path(),
                    null,
                    null,
                    30,
                );
                $process->start();
                $workers[$role] = $process;
                IsolatedOutboxDatabase::wait($directory.'/'.$role.'-ready');
            }

            touch($directory.'/go');

            $results = [];
            foreach ($workers as $role => $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[$role] = json_decode(trim($worker->getOutput()), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame($results[$role]['accepted'], count($results[$role]['pushed']));
            }

            $this->assertFileExists($directory.'/first-selected');
            $this->assertFileExists($directory.'/second-selected');

            $pushed = array_merge($results['first']['pushed'], $results['second']['pushed']);
            $this->assertSame(40, $results['first']['accepted'] + $results['second']['accepted']);

            $pushedIds = array_map(static fn (array $job): int => (int) $job['id'], $pushed);
            $pushedTokens = array_map(static fn (array $job): string => (string) $job['token'], $pushed);
            sort($pushedIds);
            $expectedIds = $deliveryIds;
            sort($expectedIds);

            $this->assertSame($expectedIds, $pushedIds);
            $this->assertCount(40, array_unique($pushedIds));
            $this->assertCount(40, array_unique($pushedTokens));

            foreach ($pushedTokens as $token) {
                $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
            }

            $rows = DB::table('outbox_deliveries')->get()->keyBy('id');
            $this->assertSame(40, (int) DB::table('outbox_deliveries')->where('state', 'leased')->count());

            foreach ($pushed as $job) {
                $row = $rows->get((int) $job['id']);
                $this->assertSame('leased', $row->state);
                $this->assertSame($job['token'], $row->lease_token);
                $this->assertSame(0, (int) $row->attempts);
                $this->assertNotNull($row->lease_expires_at);
            }
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
