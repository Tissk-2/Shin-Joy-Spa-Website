<?php

namespace Tests\Feature\Outbox;

use App\Enums\OutboxDeliveryState;
use App\Jobs\SendTelegramBookingNotification;
use App\Models\Booking;
use App\Models\OutboxDelivery;
use App\Models\OutboxMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingOutboxEndToEndTest extends TestCase
{
    use DatabaseMigrations;

    private const CHAT_ID = '-100123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-06 09:00:00', 'Asia/Jakarta'));
        $this->seed(ServiceCatalogSeeder::class);
        config([
            'services.telegram.booking_notifications_enabled' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.staff_chat_id' => self::CHAT_ID,
            'services.telegram.message_thread_id' => null,
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_booking_creation_defers_delivery_until_dispatch_sends_one_message(): void
    {
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200),
        ]);

        $booking = $this->submitBooking();

        Http::assertNothingSent();
        $message = OutboxMessage::query()->sole();
        $this->assertSame('booking.created:'.$booking->id, $message->deduplication_key);
        $this->assertSame(['booking_id' => $booking->id], $message->payload);
        $delivery = $message->deliveries()->sole();
        $this->assertSame(OutboxDeliveryState::Pending, $delivery->state);

        $this->artisan('outbox:dispatch')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === $this->endpoint()
            && $request['chat_id'] === self::CHAT_ID
            && str_contains((string) $request['text'], (string) $booking->reference));

        $delivery->refresh();
        $this->assertSame(OutboxDeliveryState::Completed, $delivery->state);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_a_retryable_telegram_failure_schedules_a_retry(): void
    {
        Http::fake([
            $this->endpoint() => Http::response(['ok' => false, 'error_code' => 500], 500),
        ]);
        $this->submitBooking();

        $this->artisan('outbox:dispatch')->assertExitCode(0);

        Http::assertSentCount(1);
        $delivery = OutboxDelivery::query()->sole();
        $this->assertSame(OutboxDeliveryState::Retry, $delivery->state);
        $this->assertSame('retryable: telegram_http', $delivery->last_error);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_a_permanent_telegram_failure_marks_the_delivery_failed(): void
    {
        Http::fake([
            $this->endpoint() => Http::response(['ok' => false, 'error_code' => 400], 400),
        ]);
        $this->submitBooking();

        $this->artisan('outbox:dispatch')->assertExitCode(0);

        Http::assertSentCount(1);
        $delivery = OutboxDelivery::query()->sole();
        $this->assertSame(OutboxDeliveryState::Failed, $delivery->state);
        $this->assertSame('permanent: telegram_http', $delivery->last_error);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_a_legacy_job_run_before_dispatch_still_sends_exactly_one_message(): void
    {
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 321]], 200),
        ]);
        $booking = $this->submitBooking();
        $message = OutboxMessage::query()->sole();

        $job = new SendTelegramBookingNotification($booking->id);
        $this->app->call([$job, 'handle']);

        $this->assertSame(1, OutboxMessage::query()->count());
        $this->assertSame(1, OutboxDelivery::query()->count());
        $this->assertSame($message->id, OutboxMessage::query()->sole()->id);

        $this->artisan('outbox:dispatch')->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertSame(OutboxDeliveryState::Completed, OutboxDelivery::query()->sole()->state);
    }

    private function submitBooking(): Booking
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/bookings', $this->validPayload())
            ->assertStatus(303);

        return Booking::query()->sole();
    }

    private function endpoint(): string
    {
        return 'https://api.telegram.org/bottest-token/sendMessage';
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
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
        ];
    }
}
