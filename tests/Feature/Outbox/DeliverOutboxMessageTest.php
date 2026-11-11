<?php

namespace Tests\Feature\Outbox;

use App\Enums\OutboxDeliveryState;
use App\Exceptions\Outbox\OutboxSkippedException;
use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxDelivery;
use App\Models\OutboxMessage;
use App\Support\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\Fixtures\Outbox\CallbackOutboxHandler;
use Tests\Fixtures\Outbox\FakeOutboxHandler;
use Tests\Fixtures\Outbox\PermanentOutboxHandler;
use Tests\Fixtures\Outbox\RetryableOutboxHandler;
use Tests\Fixtures\Outbox\ThrowingConstructorOutboxHandler;
use Tests\Fixtures\Outbox\UnexpectedOutboxHandler;
use Tests\Fixtures\Outbox\UnresolvableOutboxHandler;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/outbox/CallbackOutboxHandler.php';
require_once __DIR__.'/../../fixtures/outbox/FakeOutboxHandler.php';
require_once __DIR__.'/../../fixtures/outbox/PermanentOutboxHandler.php';
require_once __DIR__.'/../../fixtures/outbox/RetryableOutboxHandler.php';
require_once __DIR__.'/../../fixtures/outbox/ThrowingConstructorOutboxHandler.php';
require_once __DIR__.'/../../fixtures/outbox/UnexpectedOutboxHandler.php';
require_once __DIR__.'/../../fixtures/outbox/UnresolvableOutboxHandler.php';

class DeliverOutboxMessageTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00'));

        FakeOutboxHandler::$handled = [];
        CallbackOutboxHandler::$callback = null;
        RetryableOutboxHandler::$reason = 'temporary failure';
        PermanentOutboxHandler::$reason = 'permanent failure';
    }

    protected function tearDown(): void
    {
        CallbackOutboxHandler::$callback = null;
        $this->travelBack();

        parent::tearDown();
    }

    public function test_a_handler_returning_normally_completes_the_delivery(): void
    {
        $delivery = $this->createDelivery('success', FakeOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Completed, $delivery->state);
        $this->assertSame('2026-11-03 10:00:00', $delivery->completed_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $delivery->attempts);
        $this->assertNull($delivery->lease_token);
        $this->assertNull($delivery->lease_expires_at);
        $this->assertNull($delivery->last_error);
        $this->assertNull($delivery->skipped_at);
        $this->assertNull($delivery->failed_at);
        $this->assertSame([$delivery->message_id], FakeOutboxHandler::$handled);
    }

    public function test_retryable_failures_use_the_exact_backoff_sequence(): void
    {
        $delivery = $this->createDelivery('retry-backoff', RetryableOutboxHandler::class);
        $expected = [
            1 => ['2026-11-03 10:01:00', 60],
            2 => ['2026-11-03 10:06:00', 300],
            3 => ['2026-11-03 10:21:00', 900],
            4 => ['2026-11-03 11:21:00', 3600],
        ];

        foreach ($expected as $attempt => [$availableAt, $backoff]) {
            $token = $this->token();
            $this->lease($delivery, $token, attempts: $attempt - 1);
            $this->deliver($delivery->id, $token);

            $delivery->refresh();
            $this->assertSame(OutboxDeliveryState::Retry, $delivery->state);
            $this->assertSame($attempt, $delivery->attempts);
            $this->assertSame($availableAt, $delivery->available_at->format('Y-m-d H:i:s'));
            $this->assertSame('retryable: temporary failure', $delivery->last_error);
            $this->assertNull($delivery->lease_token);
            $this->assertNull($delivery->lease_expires_at);

            $this->travelTo(now()->addSeconds($backoff));
        }
    }

    public function test_a_retryable_failure_at_max_attempts_fails_the_delivery(): void
    {
        $delivery = $this->createDelivery('retry-exhausted', RetryableOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token, attempts: 4);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Failed, $delivery->state);
        $this->assertSame(5, $delivery->attempts);
        $this->assertSame('2026-11-03 10:00:00', $delivery->failed_at->format('Y-m-d H:i:s'));
        $this->assertSame('retryable: temporary failure', $delivery->last_error);
        $this->assertNull($delivery->lease_token);
        $this->assertNull($delivery->lease_expires_at);
        $this->assertNull($delivery->completed_at);
    }

    public function test_a_permanent_exception_fails_the_delivery(): void
    {
        PermanentOutboxHandler::$reason = 'invalid booking';
        $delivery = $this->createDelivery('permanent', PermanentOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Failed, $delivery->state);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('2026-11-03 10:00:00', $delivery->failed_at->format('Y-m-d H:i:s'));
        $this->assertSame('permanent: invalid booking', $delivery->last_error);
        $this->assertNull($delivery->lease_token);
        $this->assertNull($delivery->lease_expires_at);
    }

    public function test_a_skipped_handler_records_a_final_skipped_delivery(): void
    {
        CallbackOutboxHandler::$callback = fn () => throw new OutboxSkippedException('superseded');
        $delivery = $this->createDelivery('skipped', CallbackOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Skipped, $delivery->state);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('2026-11-03 10:00:00', $delivery->skipped_at->format('Y-m-d H:i:s'));
        $this->assertSame('skipped: superseded', $delivery->last_error);
        $this->assertNull($delivery->completed_at);
        $this->assertNull($delivery->failed_at);
        $this->assertNull($delivery->lease_token);
        $this->assertNull($delivery->lease_expires_at);
    }

    public function test_a_lease_lost_before_a_skip_is_not_overwritten(): void
    {
        $delivery = $this->createDelivery('lost-lease-skipped', CallbackOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token);
        $newToken = $this->token();

        CallbackOutboxHandler::$callback = function () use ($delivery, $newToken): void {
            // Another worker re-leased the delivery while this attempt was still running.
            DB::table('outbox_deliveries')->where('id', $delivery->id)->update([
                'lease_token' => $newToken,
                'lease_expires_at' => now()->addMinutes(10),
            ]);

            throw new OutboxSkippedException('superseded');
        };

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Leased, $delivery->state);
        $this->assertSame($newToken, $delivery->lease_token);
        $this->assertNull($delivery->skipped_at);
        $this->assertNull($delivery->last_error);
    }

    public function test_an_untyped_exception_retries_and_stores_only_its_class_name(): void
    {
        $delivery = $this->createDelivery('unexpected', UnexpectedOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Retry, $delivery->state);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('unexpected: RuntimeException', $delivery->last_error);
        $this->assertStringNotContainsString(UnexpectedOutboxHandler::SECRET_MESSAGE, (string) $delivery->last_error);
        $this->assertNull($delivery->lease_token);
        $this->assertNull($delivery->lease_expires_at);
    }

    public function test_an_untyped_exception_at_max_attempts_fails_with_the_class_name_only(): void
    {
        $delivery = $this->createDelivery('unexpected-exhausted', UnexpectedOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token, attempts: 4);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Failed, $delivery->state);
        $this->assertSame(5, $delivery->attempts);
        $this->assertSame('unexpected: RuntimeException', $delivery->last_error);
        $this->assertNull($delivery->lease_token);
        $this->assertNull($delivery->lease_expires_at);
        $this->assertNotNull($delivery->failed_at);
    }

    public function test_last_error_strips_control_characters_and_truncates_to_five_hundred_characters(): void
    {
        RetryableOutboxHandler::$reason = "bad\x00\nreason\x1F".str_repeat('x', 600);
        $delivery = $this->createDelivery('sanitized', RetryableOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token);

        $this->deliver($delivery->id, $token);

        $lastError = (string) $delivery->refresh()->last_error;
        $this->assertStringStartsWith('retryable: badreason', $lastError);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $lastError);
        $this->assertSame(500, mb_strlen($lastError, 'UTF-8'));
    }

    public function test_a_handler_key_missing_from_current_config_fails_permanently(): void
    {
        $delivery = $this->createDelivery('missing-key', FakeOutboxHandler::class);
        config(['outbox.events' => ['booking.created' => []]]);
        $token = $this->token();
        $this->lease($delivery, $token);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Failed, $delivery->state);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('permanent: handler is not configured', $delivery->last_error);
        $this->assertNotNull($delivery->failed_at);
        $this->assertNull($delivery->lease_token);
        $this->assertNull($delivery->lease_expires_at);
        $this->assertSame([], FakeOutboxHandler::$handled);
    }

    public function test_a_removed_event_type_fails_permanently(): void
    {
        $delivery = $this->createDelivery('missing-type', FakeOutboxHandler::class);
        config(['outbox.events' => []]);
        $token = $this->token();
        $this->lease($delivery, $token);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Failed, $delivery->state);
        $this->assertSame('permanent: event type is not configured', $delivery->last_error);
        $this->assertSame([], FakeOutboxHandler::$handled);
    }

    public function test_a_malformed_events_configuration_fails_permanently(): void
    {
        $delivery = $this->createDelivery('malformed-events', FakeOutboxHandler::class);
        config(['outbox.events' => 'not-an-array']);
        $token = $this->token();
        $this->lease($delivery, $token);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Failed, $delivery->state);
        $this->assertSame('permanent: outbox.events is not an array', $delivery->last_error);
        $this->assertSame([], FakeOutboxHandler::$handled);
    }

    public function test_a_malformed_event_subscription_map_fails_permanently(): void
    {
        $delivery = $this->createDelivery('malformed-map', FakeOutboxHandler::class);
        config(['outbox.events' => ['booking.created' => 'not-an-array']]);
        $token = $this->token();
        $this->lease($delivery, $token);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Failed, $delivery->state);
        $this->assertSame('permanent: event type is not configured', $delivery->last_error);
        $this->assertSame([], FakeOutboxHandler::$handled);
    }

    public function test_a_missing_handler_class_fails_permanently(): void
    {
        $delivery = $this->createDelivery('missing-class', FakeOutboxHandler::class);
        config(['outbox.events' => ['booking.created' => ['primary' => 'App\\Handlers\\MissingOutboxHandler']]]);
        $token = $this->token();
        $this->lease($delivery, $token);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Failed, $delivery->state);
        $this->assertSame('permanent: handler class does not exist', $delivery->last_error);
        $this->assertSame([], FakeOutboxHandler::$handled);
    }

    public function test_a_non_handler_class_fails_permanently(): void
    {
        $delivery = $this->createDelivery('non-handler', FakeOutboxHandler::class);
        config(['outbox.events' => ['booking.created' => ['primary' => stdClass::class]]]);
        $token = $this->token();
        $this->lease($delivery, $token);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Failed, $delivery->state);
        $this->assertSame('permanent: handler class is invalid', $delivery->last_error);
        $this->assertSame([], FakeOutboxHandler::$handled);
    }

    public function test_a_stale_token_never_invokes_the_handler_or_writes(): void
    {
        $delivery = $this->createDelivery('stale-token', FakeOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token);
        $before = $this->row($delivery->id);

        $this->deliver($delivery->id, $this->token());

        $this->assertEquals($before, $this->row($delivery->id));
        $this->assertSame([], FakeOutboxHandler::$handled);
    }

    public function test_an_expired_lease_never_invokes_the_handler_or_writes(): void
    {
        $delivery = $this->createDelivery('expired-lease', FakeOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token, now()->subSecond());
        $before = $this->row($delivery->id);

        $this->deliver($delivery->id, $token);

        $this->assertEquals($before, $this->row($delivery->id));
        $this->assertSame([], FakeOutboxHandler::$handled);
    }

    public function test_a_non_leased_state_never_invokes_the_handler_or_writes(): void
    {
        $delivery = $this->createDelivery('non-leased', FakeOutboxHandler::class);
        $token = $this->token();
        DB::table('outbox_deliveries')->where('id', $delivery->id)->update([
            'state' => 'pending',
            'attempts' => 2,
            'lease_token' => $token,
            'lease_expires_at' => now()->addMinutes(5),
        ]);
        $delivery->refresh();
        $before = $this->row($delivery->id);

        $this->deliver($delivery->id, $token);

        $this->assertEquals($before, $this->row($delivery->id));
        $this->assertSame([], FakeOutboxHandler::$handled);
    }

    public function test_a_lease_lost_during_the_handler_is_not_overwritten(): void
    {
        $delivery = $this->createDelivery('lost-lease', CallbackOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token);
        $newToken = $this->token();
        $invocations = 0;

        CallbackOutboxHandler::$callback = function (OutboxMessage $message) use ($delivery, $newToken, &$invocations): void {
            $invocations++;
            DB::table('outbox_deliveries')->where('id', $delivery->id)->update([
                'lease_token' => $newToken,
                'lease_expires_at' => now()->addMinutes(10),
            ]);
        };

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(1, $invocations);
        $this->assertSame(OutboxDeliveryState::Leased, $delivery->state);
        $this->assertSame($newToken, $delivery->lease_token);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNull($delivery->completed_at);
        $this->assertNull($delivery->last_error);
    }

    public function test_invalid_utf8_and_c1_controls_keep_a_visible_sanitized_error(): void
    {
        $delivery = $this->createDelivery('invalid-utf8', RetryableOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token);
        RetryableOutboxHandler::$reason = "bad \xFF byte\u{0085}next";

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Retry, $delivery->state);
        $this->assertSame('retryable: bad ? bytenext', $delivery->last_error);
    }

    public function test_a_handler_the_container_cannot_build_fails_permanently(): void
    {
        $delivery = $this->createDelivery('unresolvable', UnresolvableOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Failed, $delivery->state);
        $this->assertSame('permanent: handler could not be resolved', $delivery->last_error);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNull($delivery->lease_token);
    }

    public function test_a_handler_constructor_failure_retries_with_the_class_name_only(): void
    {
        $delivery = $this->createDelivery('throwing-constructor', ThrowingConstructorOutboxHandler::class);
        $token = $this->token();
        $this->lease($delivery, $token);

        $this->deliver($delivery->id, $token);

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Retry, $delivery->state);
        $this->assertSame('unexpected: RuntimeException', $delivery->last_error);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNull($delivery->lease_token);
        $this->assertNull($delivery->lease_expires_at);
        $this->assertSame('2026-11-03 10:01:00', $delivery->available_at->format('Y-m-d H:i:s'));
    }

    public function test_a_lease_lost_before_a_failure_outcome_is_not_overwritten(): void
    {
        foreach (['retry' => RetryableOutboxHandler::class, 'failed' => PermanentOutboxHandler::class] as $outcome => $thrower) {
            $delivery = $this->createDelivery('lost-lease-'.$outcome, CallbackOutboxHandler::class);
            $token = $this->token();
            $this->lease($delivery, $token);
            $newToken = $this->token();

            CallbackOutboxHandler::$callback = function (OutboxMessage $message) use ($delivery, $newToken, $thrower): void {
                DB::table('outbox_deliveries')->where('id', $delivery->id)->update([
                    'lease_token' => $newToken,
                    'lease_expires_at' => now()->addMinutes(10),
                ]);
                app($thrower)->handle($message);
            };

            $this->deliver($delivery->id, $token);

            $delivery->refresh();
            $this->assertSame(OutboxDeliveryState::Leased, $delivery->state, $outcome);
            $this->assertSame($newToken, $delivery->lease_token, $outcome);
            $this->assertNull($delivery->last_error, $outcome);
            $this->assertNull($delivery->failed_at, $outcome);
        }
    }

    private function createDelivery(string $key, string $handlerClass): OutboxDelivery
    {
        config(['outbox.events' => ['booking.created' => ['primary' => $handlerClass]]]);

        $message = DB::transaction(fn (): OutboxMessage => app(OutboxWriter::class)->record(
            'booking.created',
            $key,
            ['booking_id' => 99],
        ));

        return $message->deliveries()->where('handler_key', 'primary')->firstOrFail();
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

    private function token(): string
    {
        return bin2hex(random_bytes(32));
    }
}
