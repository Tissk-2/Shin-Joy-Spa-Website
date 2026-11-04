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
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Outbox\FakeOutboxHandler;
use Tests\Fixtures\Outbox\RetryableOutboxHandler;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/outbox/FakeOutboxHandler.php';
require_once __DIR__.'/../../fixtures/outbox/RetryableOutboxHandler.php';

class OutboxRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00'));

        FakeOutboxHandler::$handled = [];
        RetryableOutboxHandler::$reason = 'temporary failure';
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_failures_stay_isolated_between_handlers_of_the_same_message(): void
    {
        $message = $this->record('isolation', [
            'ok' => FakeOutboxHandler::class,
            'fail' => RetryableOutboxHandler::class,
        ]);
        $ok = $message->deliveries()->where('handler_key', 'ok')->firstOrFail();
        $fail = $message->deliveries()->where('handler_key', 'fail')->firstOrFail();
        $okToken = $this->token();
        $failToken = $this->token();
        $this->lease($ok, $okToken);
        $this->lease($fail, $failToken);

        $this->deliver($ok->id, $okToken);
        $this->deliver($fail->id, $failToken);

        $ok->refresh();
        $this->assertSame(OutboxDeliveryState::Completed, $ok->state);
        $this->assertSame(1, $ok->attempts);
        $this->assertNull($ok->last_error);
        $this->assertNotNull($ok->completed_at);

        $fail->refresh();
        $this->assertSame(OutboxDeliveryState::Retry, $fail->state);
        $this->assertSame(1, $fail->attempts);
        $this->assertSame('retryable: temporary failure', $fail->last_error);
        $this->assertSame('2026-11-03 10:01:00', $fail->available_at->format('Y-m-d H:i:s'));
        $this->assertNull($fail->completed_at);
        $this->assertSame([$message->id], FakeOutboxHandler::$handled);
    }

    public function test_a_delivery_left_leased_by_a_dead_process_is_reclaimed_after_expiry_and_completes(): void
    {
        $delivery = $this->createDelivery('process-death');
        $deadToken = $this->token();
        $this->lease($delivery, $deadToken, now()->subSecond(), attempts: 1);
        Queue::fake();

        $this->assertSame(1, $this->dispatcher()->dispatchBatch(10));

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Leased, $delivery->state);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotSame($deadToken, $delivery->lease_token);
        $this->assertSame('2026-11-03 10:05:00', $delivery->lease_expires_at->format('Y-m-d H:i:s'));

        $job = Queue::pushed(DeliverOutboxMessage::class)->sole();
        $this->assertSame($delivery->id, $job->deliveryId);
        $this->assertSame($delivery->lease_token, $job->leaseToken);

        $job->handle();

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Completed, $delivery->state);
        $this->assertSame(2, $delivery->attempts);
        $this->assertSame('2026-11-03 10:00:00', $delivery->completed_at->format('Y-m-d H:i:s'));
        $this->assertNull($delivery->lease_token);
        $this->assertSame([$delivery->message_id], FakeOutboxHandler::$handled);
    }

    public function test_a_stale_worker_cannot_overwrite_the_new_owners_completion(): void
    {
        $delivery = $this->createDelivery('stale-worker');
        $oldToken = $this->token();
        $this->lease($delivery, $oldToken, now()->subSecond(), attempts: 1);
        Queue::fake();
        $this->assertSame(1, $this->dispatcher()->dispatchBatch(10));

        $newToken = $delivery->refresh()->lease_token;
        $this->assertNotSame($oldToken, $newToken);
        $claimed = $this->row($delivery->id);

        $this->deliver($delivery->id, $oldToken);

        $this->assertEquals($claimed, $this->row($delivery->id));
        $this->assertSame([], FakeOutboxHandler::$handled);

        $job = Queue::pushed(DeliverOutboxMessage::class)->sole();
        $this->assertSame($newToken, $job->leaseToken);
        $job->handle();

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Completed, $delivery->state);
        $this->assertSame(2, $delivery->attempts);
        $this->assertSame([$delivery->message_id], FakeOutboxHandler::$handled);

        $completed = $this->row($delivery->id);
        $this->deliver($delivery->id, $oldToken);
        $this->assertEquals($completed, $this->row($delivery->id));
        $this->assertSame([$delivery->message_id], FakeOutboxHandler::$handled);
    }

    public function test_a_failed_delivery_stays_failed_and_is_not_redispatched(): void
    {
        $failed = $this->createDelivery('failed-visible');
        DB::table('outbox_deliveries')->where('id', $failed->id)->update([
            'state' => 'failed',
            'attempts' => 5,
            'failed_at' => now()->subMinute(),
            'last_error' => 'permanent: invalid booking',
        ]);
        $failed->refresh();
        $neighbour = $this->createDelivery('failed-neighbour');
        Queue::fake();

        $this->assertSame(1, $this->dispatcher()->dispatchBatch(10));

        $failed->refresh();
        $this->assertSame(OutboxDeliveryState::Failed, $failed->state);
        $this->assertSame(5, $failed->attempts);
        $this->assertSame('2026-11-03 09:59:00', $failed->failed_at->format('Y-m-d H:i:s'));
        $this->assertSame('permanent: invalid booking', $failed->last_error);
        $this->assertSame(OutboxDeliveryState::Leased, $neighbour->refresh()->state);
        $this->assertSame(
            [$neighbour->id],
            Queue::pushed(DeliverOutboxMessage::class)
                ->map(static fn (DeliverOutboxMessage $job): int => $job->deliveryId)
                ->values()
                ->all(),
        );

        $this->assertSame(0, $this->dispatcher()->dispatchBatch(10));
        $this->assertSame(OutboxDeliveryState::Failed, $failed->refresh()->state);
    }

    /** @param array<string, class-string> $handlers */
    private function record(string $key, array $handlers): OutboxMessage
    {
        config(['outbox.events' => ['booking.created' => $handlers]]);

        return DB::transaction(fn (): OutboxMessage => app(OutboxWriter::class)->record(
            'booking.created',
            $key,
            ['booking_id' => 7],
        ));
    }

    private function createDelivery(string $key): OutboxDelivery
    {
        return $this->record($key, ['primary' => FakeOutboxHandler::class])
            ->deliveries()
            ->where('handler_key', 'primary')
            ->firstOrFail();
    }

    private function lease(OutboxDelivery $delivery, string $token, ?CarbonInterface $expiresAt = null, int $attempts = 0): void
    {
        DB::table('outbox_deliveries')->where('id', $delivery->id)->update([
            'state' => 'leased',
            'lease_token' => $token,
            'lease_expires_at' => $expiresAt ?? now()->addMinutes(5),
            'attempts' => $attempts,
        ]);
        $delivery->refresh();
    }

    private function deliver(int $deliveryId, string $token): void
    {
        (new DeliverOutboxMessage($deliveryId, $token))->handle();
    }

    private function row(int $deliveryId): object
    {
        return DB::table('outbox_deliveries')->where('id', $deliveryId)->first();
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
