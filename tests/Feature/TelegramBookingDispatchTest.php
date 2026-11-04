<?php

namespace Tests\Feature;

use App\Enums\OutboxDeliveryState;
use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

// Booking creation must record a durable outbox intent and do no queue or
// network I/O. Execution is the outbox dispatcher's responsibility, so this
// test never calls it and may keep RefreshDatabase.
class TelegramBookingDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 09:00:00', 'Asia/Jakarta'));
        $this->seed(ServiceCatalogSeeder::class);

        // Record any attempted HTTP call so assertNothingSent is meaningful
        // without letting it reach the network.
        Http::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // Enabled + complete configuration: exactly one booking.created message
    // with one pending telegram delivery for the new booking.
    public function test_enabled_notifications_record_one_outbox_intent_for_a_new_booking(): void
    {
        $this->enableTelegramNotifications();
        Queue::fake();

        $user = User::factory()->create(['email' => 'AYU@Example.TEST']);

        $response = $this->actingAs($user)->post('/bookings', $this->validPayload());

        $booking = Booking::query()->firstOrFail();
        $response->assertStatus(303)->assertRedirect('/bookings/'.$booking->reference);

        $message = OutboxMessage::query()->sole();
        $this->assertSame('booking.created', $message->type);
        $this->assertSame('booking.created:'.$booking->id, $message->deduplication_key);
        $this->assertSame(['booking_id' => $booking->id], $message->payload);

        $delivery = $message->deliveries()->sole();
        $this->assertSame('telegram', $delivery->handler_key);
        $this->assertSame(OutboxDeliveryState::Pending, $delivery->state);

        $this->assertOutboxCounts(1, 1);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // An identical idempotent replay must not record a second intent.
    public function test_identical_idempotent_replay_records_no_additional_intent(): void
    {
        $this->enableTelegramNotifications();
        Queue::fake();

        $user = User::factory()->create();
        $payload = $this->validPayload();

        $first = $this->actingAs($user)->post('/bookings', $payload);
        $reference = Booking::query()->sole()->reference;

        $second = $this->actingAs($user)->post('/bookings', $payload);

        $first->assertStatus(303)->assertRedirect('/bookings/'.$reference);
        $second->assertStatus(303)->assertRedirect('/bookings/'.$reference);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertOutboxCounts(1, 1);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // A successful booking while disabled must create the row and record no intent.
    public function test_disabled_notifications_create_the_booking_and_record_zero_intents(): void
    {
        config(['services.telegram.booking_notifications_enabled' => false]);
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/bookings', $this->validPayload());

        $booking = Booking::query()->firstOrFail();
        $response->assertStatus(303)->assertRedirect('/bookings/'.$booking->reference);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertOutboxCounts(0, 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // Validation failure: no row, no intent.
    public function test_validation_failure_records_zero_intents(): void
    {
        $this->enableTelegramNotifications();
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/booking')
            ->post('/bookings', $this->validPayload(['submission_key' => 'not-a-uuid']))
            ->assertRedirect('/booking')
            ->assertSessionHasErrors('submission_key');

        $this->assertDatabaseCount('bookings', 0);
        $this->assertOutboxCounts(0, 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // Guest request: redirected to login, no row, no intent.
    public function test_guest_request_records_zero_intents(): void
    {
        $this->enableTelegramNotifications();
        Queue::fake();

        $this->post('/bookings', $this->validPayload())
            ->assertRedirect(route('login', absolute: false));

        $this->assertDatabaseCount('bookings', 0);
        $this->assertOutboxCounts(0, 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // Unverified customer: redirected to verification, no row, no intent.
    public function test_unverified_customer_records_zero_intents(): void
    {
        $this->enableTelegramNotifications();
        Queue::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post('/bookings', $this->validPayload())
            ->assertRedirect(route('verification.notice', absolute: false));

        $this->assertDatabaseCount('bookings', 0);
        $this->assertOutboxCounts(0, 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // Each accepted request records its own notification intent, even at the same time.
    public function test_overlapping_requests_each_record_a_notification_intent(): void
    {
        $this->enableTelegramNotifications();
        Queue::fake();

        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->actingAs($firstUser)->post('/bookings', $this->validPayload())->assertStatus(303);

        $this->actingAs($secondUser)
            ->post('/bookings', [
                ...$this->validPayload(),
                'submission_key' => Str::uuid()->toString(),
            ])
            ->assertStatus(303);

        $this->assertDatabaseCount('bookings', 2);
        $this->assertOutboxCounts(2, 2);

        $expectedKeys = Booking::query()->orderBy('id')->get()
            ->map(static fn (Booking $booking): string => 'booking.created:'.$booking->id)
            ->all();
        $recordedKeys = OutboxMessage::query()->orderBy('id')->get()
            ->map(static fn (OutboxMessage $message): string => $message->deduplication_key)
            ->all();

        $this->assertSame($expectedKeys, $recordedKeys);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // A failed creation never records an intent. The unique-race technique
    // throws inside Booking::forceCreate, before the record statement can run,
    // so this proves the failed path records nothing.
    public function test_failed_booking_creation_records_no_intent(): void
    {
        $this->enableTelegramNotifications();
        Queue::fake();

        $user = User::factory()->create();
        $payload = $this->validPayload([
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a99',
        ]);
        $insertedByRace = false;

        Booking::creating(function (Booking $booking) use (&$insertedByRace): void {
            if ($insertedByRace) {
                return;
            }

            $insertedByRace = true;

            DB::table('bookings')->insert([
                ...$booking->getAttributes(),
                'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL99',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $exception = null;

        try {
            $this->withoutExceptionHandling()
                ->actingAs($user)
                ->post('/bookings', $payload);
        } catch (QueryException $queryException) {
            $exception = $queryException;
        }

        $this->assertInstanceOf(QueryException::class, $exception);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertOutboxCounts(0, 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_a_failure_after_the_intent_is_recorded_rolls_back_booking_and_outbox_rows(): void
    {
        $this->enableTelegramNotifications();
        Queue::fake();
        $user = User::factory()->create();

        // Fails the last write of the booking transaction, after the message row exists.
        $messageInserted = false;
        DB::beforeExecuting(function (string $query) use (&$messageInserted): void {
            if (stripos($query, 'insert or ignore into "outbox_messages"') === 0) {
                $messageInserted = true;
            }

            if (stripos($query, 'insert into "outbox_deliveries"') === 0) {
                throw new \RuntimeException('delivery insert failed');
            }
        });

        try {
            $this->withoutExceptionHandling()->actingAs($user)->post('/bookings', $this->validPayload());
            $this->fail('The booking request must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('delivery insert failed', $exception->getMessage());
        }

        $this->assertTrue($messageInserted, 'The intent row must be written before the failure.');
        $this->assertDatabaseCount('bookings', 0);
        $this->assertOutboxCounts(0, 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // Enabled but incomplete configuration still records the intent;
    // configuration completeness is the handler's responsibility, so a
    // misconfigured deployment stays observable.
    public function test_enabled_with_incomplete_configuration_still_records_the_intent(): void
    {
        config([
            'services.telegram.booking_notifications_enabled' => true,
            'services.telegram.bot_token' => '',
            'services.telegram.staff_chat_id' => '-1001234567890',
        ]);
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/bookings', $this->validPayload());

        $booking = Booking::query()->firstOrFail();
        $response->assertStatus(303)->assertRedirect('/bookings/'.$booking->reference);
        $this->assertOutboxCounts(1, 1);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    private function assertOutboxCounts(int $messages, int $deliveries): void
    {
        $this->assertDatabaseCount('outbox_messages', $messages);
        $this->assertDatabaseCount('outbox_deliveries', $deliveries);
    }

    private function enableTelegramNotifications(): void
    {
        config([
            'services.telegram.booking_notifications_enabled' => true,
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.staff_chat_id' => '-1001234567890',
            'services.telegram.message_thread_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'name' => 'Ayu Lestari',
            'phone' => '+62 812-3456-7890',
            'service' => 'nezuko',
            'mode' => 'regular',
            'duration' => 90,
            'extension' => 'nezuko-regular-extra-60',
            'date' => '2026-09-07',
            'time' => '10:30',
            'guests' => 1,
            'notes' => 'Focus on shoulders.',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10',
            'expected_total_price_idr' => 340000,
            ...$overrides,
        ];
    }
}
