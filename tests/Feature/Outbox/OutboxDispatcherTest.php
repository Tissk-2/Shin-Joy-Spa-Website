<?php

namespace Tests\Feature\Outbox;

use App\Enums\OutboxDeliveryState;
use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxDelivery;
use App\Models\OutboxMessage;
use App\Support\Outbox\OutboxDispatcher;
use App\Support\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\Fixtures\Outbox\FakeOutboxHandler;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/outbox/FakeOutboxHandler.php';

class OutboxDispatcherTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_dispatch_batch_rejects_limits_outside_the_supported_range(): void
    {
        Queue::fake();
        $dispatcher = $this->dispatcher();

        foreach ([0, -1, 1001] as $limit) {
            try {
                $dispatcher->dispatchBatch($limit);
                $this->fail("Expected limit [{$limit}] to be rejected.");
            } catch (InvalidArgumentException) {
                // Expected: only 1..1000 is supported.
            }
        }

        $this->assertSame(0, $dispatcher->dispatchBatch(1));
        $this->assertSame(0, $dispatcher->dispatchBatch(1000));
    }

    public function test_only_due_pending_retry_and_expired_leased_deliveries_are_claimed(): void
    {
        Queue::fake();
        $now = now()->toImmutable();

        $duePending = $this->createDelivery('due-pending', $now->subMinute());
        $dueRetry = $this->createDelivery('due-retry', $now->subMinute());
        $this->updateDelivery($dueRetry, ['state' => 'retry', 'attempts' => 1]);

        $futurePending = $this->createDelivery('future-pending', $now->addMinute());
        $futureRetry = $this->createDelivery('future-retry', $now->addMinute());
        $this->updateDelivery($futureRetry, ['state' => 'retry', 'attempts' => 1]);

        $activeLease = $this->createDelivery('active-lease', $now->subMinute());
        $activeToken = $this->token();
        $this->updateDelivery($activeLease, [
            'state' => 'leased',
            'attempts' => 1,
            'lease_token' => $activeToken,
            'lease_expires_at' => $now->addMinute(),
        ]);

        $expiredLease = $this->createDelivery('expired-lease', $now->subMinute());
        $expiredToken = $this->token();
        $this->updateDelivery($expiredLease, [
            'state' => 'leased',
            'attempts' => 2,
            'lease_token' => $expiredToken,
            'lease_expires_at' => $now->subSecond(),
        ]);

        $this->assertSame(3, $this->dispatcher()->dispatchBatch(10));

        $this->assertSame(OutboxDeliveryState::Leased, $duePending->refresh()->state);
        $this->assertSame(OutboxDeliveryState::Leased, $dueRetry->refresh()->state);
        $this->assertSame(OutboxDeliveryState::Leased, $expiredLease->refresh()->state);
        $this->assertNotSame($expiredToken, $expiredLease->lease_token);
        $this->assertSame(OutboxDeliveryState::Pending, $futurePending->refresh()->state);
        $this->assertSame(OutboxDeliveryState::Retry, $futureRetry->refresh()->state);
        $this->assertSame(OutboxDeliveryState::Leased, $activeLease->refresh()->state);
        $this->assertSame($activeToken, $activeLease->lease_token);
        $this->assertCount(3, Queue::pushed(DeliverOutboxMessage::class));
    }

    public function test_a_skipped_delivery_is_never_claimed_again(): void
    {
        Queue::fake();
        $skipped = $this->createDelivery('skipped', now()->toImmutable()->subMinute());
        $this->updateDelivery($skipped, [
            'state' => 'skipped',
            'attempts' => 1,
            'skipped_at' => now(),
            'last_error' => 'skipped: disabled',
        ]);

        $this->assertSame(0, $this->dispatcher()->dispatchBatch(10));

        $this->assertSame(OutboxDeliveryState::Skipped, $skipped->refresh()->state);
        Queue::assertNothingPushed();
    }

    public function test_claims_follow_available_at_then_id_and_respect_the_limit(): void
    {
        Queue::fake();
        $now = now()->toImmutable();

        $late = $this->createDelivery('order-late', $now->addMinutes(5));
        $first = $this->createDelivery('order-first', $now->subMinutes(2));
        $second = $this->createDelivery('order-second', $now->subMinute());
        $third = $this->createDelivery('order-third', $now->subMinute());

        $this->assertSame(3, $this->dispatcher()->dispatchBatch(3));

        $pushedIds = Queue::pushed(DeliverOutboxMessage::class)
            ->map(static fn (DeliverOutboxMessage $job): int => $job->deliveryId)
            ->values()
            ->all();

        $this->assertSame([$first->id, $second->id, $third->id], $pushedIds);
        $this->assertSame(OutboxDeliveryState::Pending, $late->refresh()->state);
    }

    public function test_the_limit_caps_how_many_claims_one_call_makes(): void
    {
        Queue::fake();

        for ($index = 0; $index < 4; $index++) {
            $this->createDelivery('limit-'.$index);
        }

        $this->assertSame(2, $this->dispatcher()->dispatchBatch(2));
        $this->assertSame(2, DB::table('outbox_deliveries')->where('state', 'leased')->count());
        $this->assertCount(2, Queue::pushed(DeliverOutboxMessage::class));
    }

    public function test_a_claim_leases_with_a_fresh_token_and_does_not_complete_the_delivery(): void
    {
        Queue::fake();
        $now = now()->toImmutable();
        $deliveries = collect([
            $this->createDelivery('claim-a'),
            $this->createDelivery('claim-b'),
            $this->createDelivery('claim-c'),
        ]);

        $this->assertSame(3, $this->dispatcher()->dispatchBatch(3));

        $jobs = Queue::pushed(DeliverOutboxMessage::class)->values();
        $tokens = [];

        foreach ($deliveries as $delivery) {
            $delivery->refresh();
            $this->assertSame(OutboxDeliveryState::Leased, $delivery->state);
            $this->assertSame(0, $delivery->attempts);
            $this->assertNull($delivery->completed_at);
            $this->assertNull($delivery->skipped_at);
            $this->assertNull($delivery->failed_at);
            $this->assertNull($delivery->last_error);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $delivery->lease_token);
            $this->assertSame(
                $now->addSeconds(300)->format('Y-m-d H:i:s'),
                $delivery->lease_expires_at->format('Y-m-d H:i:s'),
            );
            $tokens[] = $delivery->lease_token;
        }

        $this->assertSame($tokens, array_values(array_unique($tokens)));
        $this->assertSame(
            $deliveries->map(static fn (OutboxDelivery $delivery): int => $delivery->id)->sort()->values()->all(),
            $jobs->map(static fn (DeliverOutboxMessage $job): int => $job->deliveryId)->sort()->values()->all(),
        );

        foreach ($jobs as $job) {
            $this->assertSame(
                $deliveries->firstWhere('id', $job->deliveryId)->lease_token,
                $job->leaseToken,
            );
        }
    }

    public function test_push_failure_restores_only_that_delivery_to_retry_and_pushes_the_others(): void
    {
        $first = $this->createDelivery('push-first');
        $second = $this->createDelivery('push-second');
        $third = $this->createDelivery('push-third');

        $queue = new RecordingQueueFactory($second->id);
        $this->app->instance(QueueFactory::class, $queue);

        $this->assertSame(2, $this->dispatcher()->dispatchBatch(3));

        $this->assertSame(OutboxDeliveryState::Leased, $first->refresh()->state);
        $this->assertSame(OutboxDeliveryState::Retry, $second->refresh()->state);
        $this->assertSame(OutboxDeliveryState::Leased, $third->refresh()->state);
        $this->assertSame(0, $second->attempts);
        $this->assertSame('2026-11-03 10:00:00', $second->available_at->format('Y-m-d H:i:s'));
        $this->assertNull($second->lease_token);
        $this->assertNull($second->lease_expires_at);
        $this->assertSame(
            [$first->id, $third->id],
            array_map(static fn (DeliverOutboxMessage $job): int => $job->deliveryId, $queue->pushed),
        );
    }

    public function test_an_expired_lease_at_max_attempts_fails_visibly_and_is_not_pushed(): void
    {
        Queue::fake();
        $now = now()->toImmutable();
        $pending = $this->createDelivery('exhausted-neighbour');
        $exhausted = $this->createDelivery('exhausted');
        $this->updateDelivery($exhausted, [
            'state' => 'leased',
            'attempts' => 5,
            'lease_token' => $this->token(),
            'lease_expires_at' => $now->subSecond(),
        ]);

        $this->assertSame(1, $this->dispatcher()->dispatchBatch(10));

        $exhausted->refresh();
        $this->assertSame(OutboxDeliveryState::Failed, $exhausted->state);
        $this->assertSame('2026-11-03 10:00:00', $exhausted->failed_at->format('Y-m-d H:i:s'));
        $this->assertSame('retryable: lease expired after max attempts', $exhausted->last_error);
        $this->assertNull($exhausted->lease_token);
        $this->assertNull($exhausted->lease_expires_at);
        $this->assertSame(5, $exhausted->attempts);
        $this->assertSame(OutboxDeliveryState::Leased, $pending->refresh()->state);

        $pushedIds = Queue::pushed(DeliverOutboxMessage::class)
            ->map(static fn (DeliverOutboxMessage $job): int => $job->deliveryId)
            ->values()
            ->all();

        $this->assertSame([$pending->id], $pushedIds);
    }

    public function test_no_job_is_pushed_inside_the_claim_transaction(): void
    {
        $this->createDelivery('transaction-a');
        $this->createDelivery('transaction-b');

        $queue = new RecordingQueueFactory;
        $this->app->instance(QueueFactory::class, $queue);

        $this->assertSame(2, $this->dispatcher()->dispatchBatch(2));
        $this->assertSame([0, 0], $queue->transactionLevels);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_dispatch_inside_an_open_transaction_is_rejected_before_claiming(): void
    {
        $delivery = $this->createDelivery('caller-transaction');
        $queue = new RecordingQueueFactory;
        $this->app->instance(QueueFactory::class, $queue);

        DB::beginTransaction();

        try {
            $this->dispatcher()->dispatchBatch(10);
            $this->fail('dispatchBatch must reject an open caller transaction.');
        } catch (LogicException) {
            // expected
        } finally {
            DB::rollBack();
        }

        $this->assertSame(OutboxDeliveryState::Pending, $delivery->refresh()->state);
        $this->assertSame([], $queue->pushed);
    }

    public function test_the_real_sync_queue_runs_the_delivery_after_the_claim(): void
    {
        FakeOutboxHandler::$handled = [];
        $delivery = $this->createDelivery('real-sync-queue');

        $this->assertSame(1, $this->dispatcher()->dispatchBatch(10));

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Completed, $delivery->state);
        $this->assertSame(1, $delivery->attempts);
        $this->assertCount(1, FakeOutboxHandler::$handled);
    }

    private function createDelivery(string $key, ?CarbonInterface $availableAt = null): OutboxDelivery
    {
        config(['outbox.events' => ['booking.created' => ['primary' => FakeOutboxHandler::class]]]);

        $message = DB::transaction(fn (): OutboxMessage => app(OutboxWriter::class)->record(
            'booking.created',
            $key,
            ['booking_id' => 1],
        ));

        $delivery = $message->deliveries()->where('handler_key', 'primary')->firstOrFail();

        if ($availableAt instanceof CarbonInterface) {
            $this->updateDelivery($delivery, ['available_at' => $availableAt]);
        }

        return $delivery;
    }

    /** @param array<string, mixed> $attributes */
    private function updateDelivery(OutboxDelivery $delivery, array $attributes): void
    {
        DB::table('outbox_deliveries')->where('id', $delivery->id)->update($attributes);
        $delivery->refresh();
    }

    private function dispatcher(): OutboxDispatcher
    {
        return $this->app->make(OutboxDispatcher::class);
    }

    private function token(): string
    {
        return bin2hex(random_bytes(32));
    }
}

final class RecordingQueueFactory implements QueueFactory
{
    /** @var list<DeliverOutboxMessage> */
    public array $pushed = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public function __construct(private readonly ?int $failForDeliveryId = null) {}

    public function connection($name = null)
    {
        // Acts as its own connection, mirroring QueueManager::connection()->push().
        return $this;
    }

    public function push(object $job, mixed $data = '', ?string $queue = null): void
    {
        $this->transactionLevels[] = DB::transactionLevel();

        if ($job->deliveryId === $this->failForDeliveryId) {
            throw new RuntimeException('Queue push failed.');
        }

        $this->pushed[] = $job;
    }
}
