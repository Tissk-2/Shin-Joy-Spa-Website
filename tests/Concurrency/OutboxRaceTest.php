<?php

namespace Tests\Concurrency;

use App\Jobs\DeliverOutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OutboxRaceTest extends ConcurrencyTestCase
{
    /**
     * Two dispatchers read the same 20 pending deliveries. The second tries to claim the first delivery while the first
     * dispatcher holds its claim uncommitted: on MySQL it waits for the row lock, on SQLite for the write lock. Its
     * compare-and-set then matches nothing, and no delivery in the batch is claimed twice.
     */
    public function test_two_dispatchers_claim_each_delivery_once(): void
    {
        $ids = $this->deliveries(20, ['state' => 'pending', 'attempts' => 0]);

        [$first, $second] = $this->race();

        $this->assertContains($ids[0], array_column($first['pushed'], 'id'));
        $this->assertNotContains($ids[0], array_column($second['pushed'], 'id'));
        $this->assertSame(count($first['pushed']), $first['accepted']);
        $this->assertSame(count($second['pushed']), $second['accepted']);

        $pushed = array_merge($first['pushed'], $second['pushed']);
        $pushedIds = array_column($pushed, 'id');
        sort($pushedIds);
        $this->assertSame($ids, $pushedIds);
        $this->assertCount(20, array_unique(array_column($pushed, 'token')));

        $rows = DB::table('outbox_deliveries')->get()->keyBy('id');
        foreach ($pushed as $job) {
            $row = $rows->get($job['id']);
            $this->assertSame(['leased', $job['token'], 0, '2026-10-24 00:05:00'],
                [$row->state, $row->lease_token, (int) $row->attempts, substr((string) $row->lease_expires_at, 0, 19)]);
        }
    }

    /**
     * Both dispatchers read the same expired lease. The first reclaims it under a new token, and the second's claim,
     * which waited for it, matches nothing because the new lease hasn't expired. The old token's job is then refused.
     */
    public function test_two_dispatchers_reclaim_an_expired_lease_once(): void
    {
        $old = str_repeat('a', 64);
        [$id] = $this->deliveries(1, ['state' => 'leased', 'attempts' => 1, 'lease_token' => $old, 'lease_expires_at' => '2026-10-23 23:55:00']);

        [$first, $second] = $this->race();

        $this->assertSame(1, $first['accepted']);
        $this->assertSame($id, $first['pushed'][0]['id']);
        $this->assertSame(['accepted' => 0, 'pushed' => []], $second);
        $token = $first['pushed'][0]['token'];
        $this->assertNotSame($old, $token);

        // The old lease's job comes back late, while the new lease runs, and counts no attempt.
        CarbonImmutable::setTestNow('2026-10-24 00:00:00 UTC');
        (new DeliverOutboxMessage($id, $old))->handle();
        $row = DB::table('outbox_deliveries')->find($id);
        $this->assertSame(['leased', $token, 1, '2026-10-24 00:05:00'],
            [$row->state, $row->lease_token, (int) $row->attempts, substr((string) $row->lease_expires_at, 0, 19)]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<int>
     */
    private function deliveries(int $count, array $state): array
    {
        $now = '2026-10-23 23:00:00';
        $messageId = DB::table('outbox_messages')->insertGetId([
            'type' => 'booking.created',
            'deduplication_key' => 'dispatcher-race',
            'deduplication_hash' => hash('sha256', 'dispatcher-race'),
            'payload' => '{"booking_id":7}',
            'schema_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $ids = [];

        for ($index = 0; $index < $count; $index++) {
            $key = 'worker-'.$index;
            $ids[] = DB::table('outbox_deliveries')->insertGetId([
                'message_id' => $messageId,
                'handler_key' => $key,
                'handler_key_hash' => hash('sha256', $key),
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ] + $state);
        }

        return $ids;
    }

    /**
     * Runs one dispatcher batch in each worker, at the workers' time of 2026-10-24 00:00 UTC.
     *
     * @return array{0: array{accepted: int, pushed: list<array{id: int, token: string}>}, 1: array{accepted: int, pushed: list<array{id: int, token: string}>}}
     */
    private function race(): array
    {
        $this->worker('dispatch', 'first', 0);
        $this->worker('dispatch', 'second', 0);
        file_put_contents($this->directory.'/first-go', 'go');
        file_put_contents($this->directory.'/second-go', 'go');
        ConcurrencyDatabase::wait($this->directory.'/first-acquired', $this->workers['first']);
        ConcurrencyDatabase::wait($this->directory.'/second-attempting', $this->workers['second']);
        // The first dispatcher holds its claim uncommitted, so the second's claim of the same delivery can't finish.
        usleep(150000);
        $this->assertTrue($this->workers['second']->isRunning());
        $this->assertFileDoesNotExist($this->directory.'/second-acquired');
        file_put_contents($this->directory.'/release-first', 'release');

        $outputs = [];
        foreach (['first', 'second'] as $role) {
            $this->assertSame(0, $this->workers[$role]->wait(), $this->workers[$role]->getErrorOutput());
            $outputs[] = json_decode($this->workers[$role]->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }
        // The second's claim statement itself waited until the release, not a barrier before it.
        $this->assertGreaterThanOrEqual(100.0, (float) file_get_contents($this->directory.'/second-claim-ms'));

        return $outputs;
    }
}
