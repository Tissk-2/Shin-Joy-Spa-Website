<?php

namespace Tests\Feature\Jobs;

use App\Enums\OutboxDeliveryState;
use App\Jobs\SendTelegramBookingNotification;
use App\Models\Booking;
use App\Models\OutboxDelivery;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Support\Outbox\BookingCreatedOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Tests\TestCase;

// Legacy adapter contract: queued payloads from the old direct-delivery path
// must deserialize and record the same deduplicated booking.created intent.
// Nothing new dispatches this job, and it never contacts Telegram itself.
class SendTelegramBookingNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-1001234567890';

    private const MESSAGE = 'NEW BOOKING — SHIN JOY SPA';

    /**
     * In-memory log store for the privacy assertions. There is no Log::fake()
     * in this framework version, so the default log channel is pointed at a
     * capturing Monolog handler.
     */
    private \stdClass $logs;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 09:00:00', 'Asia/Jakarta'));
        $this->logs = new \stdClass;
        $this->logs->lines = [];
        $this->captureLogs();
        $this->configureTelegram();

        // Record any attempted HTTP call so assertNothingSent is meaningful
        // without letting it reach the network.
        Http::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Log::forgetChannel('telegram-contract');

        parent::tearDown();
    }

    public function test_job_exposes_the_approved_queue_contract(): void
    {
        $this->assertTrue(
            class_exists(SendTelegramBookingNotification::class),
            'App\Jobs\SendTelegramBookingNotification must remain available for queued payloads.',
        );
        $this->assertTrue(
            is_subclass_of(SendTelegramBookingNotification::class, ShouldQueue::class),
            'The notification job must implement ShouldQueue.',
        );

        $uses = class_uses_recursive(SendTelegramBookingNotification::class);
        $this->assertContains(Queueable::class, $uses);
        $this->assertContains(InteractsWithQueue::class, $uses);

        $job = new SendTelegramBookingNotification(42);
        $this->assertSame(4, $job->tries, 'The job must allow four executions maximum.');
        $this->assertSame([60, 300, 900], $job->backoff, 'Normal backoff must be 1, 5, and 15 minutes.');
        $this->assertSame(42, $job->bookingId, 'The constructor must store the booking identifier.');
        $this->assertTrue($job->afterCommit, 'Legacy payloads must still dispatch after commit.');
    }

    public function test_flag_switched_off_records_nothing(): void
    {
        $booking = $this->booking();
        config(['services.telegram.booking_notifications_enabled' => false]);

        $job = (new SendTelegramBookingNotification($booking->id))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        $this->assertSame(0, OutboxMessage::query()->count());
        $this->assertSame(0, OutboxDelivery::query()->count());
        $this->assertSame([], $this->logs->lines);
        Http::assertNothingSent();
        $job->assertNotFailed();
        $job->assertNotReleased();
    }

    public function test_it_records_the_shared_intent_and_a_telegram_delivery(): void
    {
        $booking = $this->booking();

        $job = (new SendTelegramBookingNotification($booking->id))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        $message = OutboxMessage::query()->sole();
        $this->assertSame('booking.created', $message->type);
        $this->assertSame('booking.created:'.$booking->id, $message->deduplication_key);
        $this->assertSame(['booking_id' => $booking->id], $message->payload);

        $delivery = $message->deliveries()->sole();
        $this->assertSame('telegram', $delivery->handler_key);
        $this->assertSame(OutboxDeliveryState::Pending, $delivery->state);
        $this->assertSame(0, $delivery->attempts);

        Http::assertNothingSent();
        $job->assertNotFailed();
        $job->assertNotReleased();
    }

    public function test_running_after_create_booking_deduplicates_to_the_same_message(): void
    {
        $booking = $this->booking();
        $message = DB::transaction(fn (): OutboxMessage => app(BookingCreatedOutbox::class)->record($booking));

        $job = (new SendTelegramBookingNotification($booking->id))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        $this->assertSame(1, OutboxMessage::query()->count());
        $this->assertSame(1, OutboxDelivery::query()->count());
        $this->assertSame($message->id, OutboxMessage::query()->sole()->id);
        Http::assertNothingSent();
        $job->assertNotFailed();
        $job->assertNotReleased();
    }

    public function test_missing_booking_fails_permanently_without_recording_an_intent(): void
    {
        $job = (new SendTelegramBookingNotification(999999))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        $job->assertFailedWith('booking_missing');
        $job->assertNotReleased();
        $this->assertSame(0, OutboxMessage::query()->count());
        $this->assertSame(0, OutboxDelivery::query()->count());
        Http::assertNothingSent();
        $this->assertLogsContainAll([
            'category',
            'booking_missing',
            'attempt',
            '1',
        ]);
        $this->assertLogsContainNoneOf([
            'Ayu Lestari',
            'ayu@example.com',
            '+62 812-3456-7890',
            'Focus on shoulders.',
            self::MESSAGE,
            'test-bot-token',
            'api.telegram.org',
            self::CHAT_ID,
        ]);
    }

    private function configureTelegram(): void
    {
        config([
            'services.telegram.booking_notifications_enabled' => true,
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.staff_chat_id' => self::CHAT_ID,
            'services.telegram.message_thread_id' => null,
        ]);
    }

    private function booking(): Booking
    {
        $user = User::factory()->create();

        return Booking::query()->forceCreate([
            'user_id' => $user->id,
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL01',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10',
            'request_fingerprint' => str_repeat('a', 64),
            'status' => 'pending',
            'customer_name' => 'Ayu Lestari',
            'customer_email' => 'ayu@example.com',
            'customer_phone' => '+62 812-3456-7890',
            'appointment_date' => '2026-09-21',
            'appointment_time' => '10:30',
            'appointment_timezone' => 'Asia/Jakarta',
            'guests' => 2,
            'notes' => 'Focus on shoulders.',
            'package_slug' => 'nezuko',
            'package_name_id' => 'Nezuko',
            'package_name_en' => 'Nezuko',
            'package_is_vip' => false,
            'treatment_mode' => 'regular',
            'base_choice_code' => 'nezuko-regular-90',
            'base_duration_minutes' => 90,
            'base_price_idr' => 265000,
            'extension_choice_code' => 'nezuko-regular-extra-60',
            'extension_duration_minutes' => 60,
            'extension_price_idr' => 75000,
            'total_duration_minutes' => 150,
            'total_price_idr' => 340000,
            'created_at' => CarbonImmutable::parse('2026-09-19 14:30:00', 'Asia/Jakarta'),
        ]);
    }

    private function captureLogs(): void
    {
        $store = $this->logs;

        Log::forgetChannel('telegram-contract');
        Log::extend('telegram-contract', function () use ($store) {
            $logger = new Logger('telegram-contract');
            $logger->pushHandler(new class($store) extends AbstractProcessingHandler
            {
                public function __construct(private readonly \stdClass $store)
                {
                    parent::__construct();
                }

                protected function write(LogRecord $record): void
                {
                    $this->store->lines[] = sprintf(
                        '%s: %s %s',
                        $record->level->getName(),
                        $record->message,
                        print_r($record->context, true),
                    );
                }
            });

            return $logger;
        });

        config([
            'logging.channels.telegram-contract' => ['driver' => 'telegram-contract'],
            'logging.default' => 'telegram-contract',
        ]);
    }

    /**
     * @param  list<string>  $forbidden
     */
    private function assertLogsContainNoneOf(array $forbidden): void
    {
        foreach ($this->logs->lines as $line) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $line,
                    "Log output must not contain [{$needle}]: {$line}",
                );
            }
        }
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertLogsContainAll(array $needles): void
    {
        $blob = implode("\n", $this->logs->lines);

        foreach ($needles as $needle) {
            $this->assertStringContainsString(
                $needle,
                $blob,
                "Log output was expected to contain [{$needle}].",
            );
        }
    }
}
