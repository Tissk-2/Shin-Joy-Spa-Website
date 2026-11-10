<?php

namespace Tests\Feature\Outbox;

use App\Exceptions\Outbox\OutboxPermanentException;
use App\Exceptions\Outbox\OutboxRetryableException;
use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Support\Outbox\BookingCreatedOutbox;
use App\Support\Outbox\Handlers\TelegramBookingNotificationHandler;
use App\Support\Outbox\OutboxWriter;
use App\Support\TelegramClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramBookingNotificationHandlerTest extends TestCase
{
    use DatabaseMigrations;

    private const CHAT_ID = '-1001234567890';

    private const MESSAGE = 'NEW BOOKING — SHIN JOY SPA';

    private \stdClass $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-06 09:00:00', 'Asia/Jakarta'));
        $this->logs = new \stdClass;
        $this->logs->lines = [];
        $this->captureLogs();
        $this->configureTelegram();

        // Any HTTP request not matched by a registered fake fails the suite.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Log::forgetChannel('telegram-contract');
        $this->travelBack();

        parent::tearDown();
    }

    public function test_successful_delivery_sends_the_formatted_message_to_the_configured_chat(): void
    {
        $booking = $this->booking();
        $message = $this->message($booking);
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200),
        ]);

        $this->handler()->handle($message);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === $this->endpoint()
                && $request['chat_id'] === self::CHAT_ID
                && str_contains((string) $request['text'], 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL01')
                && isset($request['link_preview_options'])
                && ($request['link_preview_options']['is_disabled'] ?? null) === true
                && ! array_key_exists('message_thread_id', $request->data())
                && ! array_key_exists('disable_web_page_preview', $request->data())
                && ! array_key_exists('parse_mode', $request->data());
        });

        $this->assertLogsContainAll([
            'SJ-01K4DR9M7JQ2ABCD3EFGHJKL01',
            'message_id',
            '123',
        ]);
        $this->assertLogsContainNoneOf([
            'test-bot-token',
            'api.telegram.org',
            self::CHAT_ID,
            self::MESSAGE,
            'Ayu Lestari',
            'ayu@example.com',
            'attempt',
        ]);
    }

    public function test_thread_id_is_supplied_only_when_configured(): void
    {
        config(['services.telegram.message_thread_id' => 77]);
        $booking = $this->booking();
        $message = $this->message($booking);
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 124]], 200),
        ]);

        $this->handler()->handle($message);

        Http::assertSent(function (Request $request): bool {
            return $request['message_thread_id'] === 77;
        });
    }

    public function test_flag_switched_off_after_recording_completes_as_a_clean_noop(): void
    {
        $booking = $this->booking();
        $message = $this->message($booking);
        config(['services.telegram.booking_notifications_enabled' => false]);

        $this->handler()->handle($message);

        Http::assertNothingSent();
        $this->assertSame([], $this->logs->lines);
    }

    public function test_missing_bot_token_is_a_permanent_configuration_failure(): void
    {
        $booking = $this->booking();
        $message = $this->message($booking);
        config(['services.telegram.bot_token' => '']);

        $this->assertPermanentFailure($message, 'configuration');

        Http::assertNothingSent();
        $this->assertLogsContainAll([
            'category',
            'configuration',
            'booking_reference',
            'SJ-01K4DR9M7JQ2ABCD3EFGHJKL01',
        ]);
        $this->assertLogsContainNoneOf([
            'test-bot-token',
            'api.telegram.org',
            self::CHAT_ID,
            self::MESSAGE,
            'Ayu Lestari',
            'ayu@example.com',
            '+62 812-3456-7890',
            'Focus on shoulders.',
        ]);
    }

    public function test_missing_chat_id_is_a_permanent_configuration_failure(): void
    {
        $booking = $this->booking();
        $message = $this->message($booking);
        config(['services.telegram.staff_chat_id' => '']);

        $this->assertPermanentFailure($message, 'configuration');

        Http::assertNothingSent();
        $this->assertLogsContainAll([
            'category',
            'configuration',
            'booking_reference',
            'SJ-01K4DR9M7JQ2ABCD3EFGHJKL01',
        ]);
        $this->assertLogsContainNoneOf([
            'test-bot-token',
            'api.telegram.org',
            self::CHAT_ID,
            self::MESSAGE,
            'Ayu Lestari',
            'ayu@example.com',
            '+62 812-3456-7890',
            'Focus on shoulders.',
        ]);
    }

    public function test_missing_booking_fails_permanently_without_contacting_telegram(): void
    {
        $booking = $this->booking();
        $message = $this->message($booking);
        DB::table('bookings')->where('id', $booking->id)->delete();

        $this->assertPermanentFailure($message, 'booking_missing');

        Http::assertNothingSent();
        $this->assertLogsContainAll([
            'category',
            'booking_missing',
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

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payloads_fail_permanently_before_any_send(mixed $bookingId): void
    {
        $message = DB::transaction(fn (): OutboxMessage => app(OutboxWriter::class)->record(
            'booking.created',
            'invalid-payload-'.md5(serialize($bookingId)),
            ['booking_id' => $bookingId],
        ));

        $this->assertPermanentFailure($message, 'payload');

        Http::assertNothingSent();
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            'numeric string' => ['42'],
            'zero' => [0],
            'negative' => [-1],
            'null' => [null],
        ];
    }

    #[DataProvider('permanentStatusProvider')]
    public function test_permanent_telegram_statuses_fail_with_the_permanent_telegram_http_category(int $status): void
    {
        $booking = $this->booking();
        $message = $this->message($booking);
        Http::fake([
            $this->endpoint() => Http::response(['ok' => false, 'error_code' => $status], $status),
        ]);

        $this->assertPermanentFailure($message, 'telegram_http');

        $this->assertLogsContainAll([
            'category',
            'telegram_http',
            'booking_reference',
            'SJ-01K4DR9M7JQ2ABCD3EFGHJKL01',
            'http_status',
            'error_code',
            (string) $status,
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

    public static function permanentStatusProvider(): array
    {
        return [[400], [401], [403], [404]];
    }

    #[DataProvider('retryableStatusProvider')]
    public function test_retryable_telegram_statuses_are_retryable_telegram_http_failures(int $status): void
    {
        $booking = $this->booking();
        $message = $this->message($booking);
        Http::fake([
            $this->endpoint() => Http::response(['ok' => false, 'error_code' => $status], $status),
        ]);

        $this->assertRetryableFailure($message, 'telegram_http');

        $this->assertLogsContainAll([
            'category',
            'telegram_http',
            'booking_reference',
            'SJ-01K4DR9M7JQ2ABCD3EFGHJKL01',
            'http_status',
            'error_code',
            (string) $status,
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

    public static function retryableStatusProvider(): array
    {
        return [[408], [500], [503]];
    }

    public function test_connection_failures_are_retryable_network_failures(): void
    {
        $booking = $this->booking();
        $message = $this->message($booking);
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->assertRetryableFailure($message, 'network');

        $this->assertLogsContainAll([
            'category',
            'network',
            'booking_reference',
            'SJ-01K4DR9M7JQ2ABCD3EFGHJKL01',
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
            'cURL error 28',
            'Operation timed out',
        ]);
    }

    public function test_rate_limit_with_retry_after_remains_retryable_and_ignores_the_telegram_delay(): void
    {
        $booking = $this->booking();
        $message = $this->message($booking);
        Http::fake([
            $this->endpoint() => Http::response([
                'ok' => false,
                'error_code' => 429,
                'description' => 'Too Many Requests',
                'parameters' => ['retry_after' => 31],
            ], 429),
        ]);

        // Telegram's retry_after is intentionally dropped: outbox backoff owns
        // the delay, so only the retryable category survives.
        $this->assertRetryableFailure($message, 'telegram_http');

        $this->assertLogsContainAll([
            'category',
            'telegram_http',
            'http_status',
            'error_code',
            '429',
        ]);
        $this->assertLogsContainNoneOf([
            'retry_after',
            'Too Many Requests',
            'test-bot-token',
            'api.telegram.org',
            self::CHAT_ID,
        ]);
    }

    public function test_formatter_over_limit_fails_permanently_with_the_formatting_category(): void
    {
        $booking = $this->booking();
        $message = $this->message($booking);
        config(['app.url' => 'https://'.str_repeat('a', 4000).'.example.invalid']);

        $this->assertPermanentFailure($message, 'formatting');

        Http::assertNothingSent();
        $this->assertLogsContainAll([
            'category',
            'formatting',
            'booking_reference',
            'SJ-01K4DR9M7JQ2ABCD3EFGHJKL01',
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
            'example.invalid',
        ]);
    }

    public function test_failure_logs_stay_within_the_privacy_allowlist(): void
    {
        $booking = $this->booking();
        $message = $this->message($booking);
        Http::fake([
            $this->endpoint() => Http::response([
                'ok' => false,
                'error_code' => 401,
                'description' => 'Unauthorized',
                'debug' => 'telegram-response-body-leak',
            ], 401),
        ]);

        $this->assertPermanentFailure($message, 'telegram_http');

        $this->assertLogsContainAll([
            'category',
            'telegram_http',
            'booking_reference',
            'SJ-01K4DR9M7JQ2ABCD3EFGHJKL01',
            'http_status',
            'error_code',
            '401',
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
            'Unauthorized',
            'telegram-response-body-leak',
            'attempt',
        ]);
    }

    public function test_the_shipped_config_maps_booking_created_to_the_telegram_handler(): void
    {
        $this->assertSame(
            ['telegram' => TelegramBookingNotificationHandler::class],
            config('outbox.events')['booking.created'],
        );
    }

    public function test_a_logic_exception_outside_the_sender_categories_is_rethrown_unchanged(): void
    {
        $booking = $this->booking();
        $message = $this->message($booking);
        $unexpected = new \LogicException('other');
        $this->app->instance(TelegramClient::class, new class($unexpected) extends TelegramClient
        {
            public function __construct(private readonly \LogicException $unexpected) {}

            public function sendMessage(string $message, string $chatId, ?int $messageThreadId = null, ?array $replyMarkup = null): int
            {
                throw $this->unexpected;
            }
        });

        try {
            $this->handler()->handle($message);
            $this->fail('The unexpected LogicException must propagate.');
        } catch (\LogicException $exception) {
            // DeliverOutboxMessage treats it as unexpected and retries; it must not become permanent.
            $this->assertSame($unexpected, $exception);
        }

        $this->assertLogsContainNoneOf(['failed permanently']);
    }

    /** @param  array<string, mixed>  $body */
    #[DataProvider('malformedRateLimitProvider')]
    public function test_malformed_rate_limit_responses_stay_retryable(array $body): void
    {
        $message = $this->message($this->booking());
        Http::fake([$this->endpoint() => Http::response($body, 429)]);

        $this->assertRetryableFailure($message, 'telegram_http');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedRateLimitProvider(): array
    {
        return [
            'non-numeric retry_after' => [['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 'soon']]],
            'empty body' => [[]],
        ];
    }

    private function handler(): TelegramBookingNotificationHandler
    {
        return $this->app->make(TelegramBookingNotificationHandler::class);
    }

    private function message(Booking $booking): OutboxMessage
    {
        return DB::transaction(fn (): OutboxMessage => app(BookingCreatedOutbox::class)->record($booking));
    }

    private function assertPermanentFailure(OutboxMessage $message, string $reason): void
    {
        try {
            $this->handler()->handle($message);
            $this->fail('Expected a permanent outbox failure.');
        } catch (OutboxPermanentException $exception) {
            $this->assertSame($reason, $exception->reason());
        }
    }

    private function assertRetryableFailure(OutboxMessage $message, string $reason): void
    {
        try {
            $this->handler()->handle($message);
            $this->fail('Expected a retryable outbox failure.');
        } catch (OutboxRetryableException $exception) {
            $this->assertSame($reason, $exception->reason());
        }
    }

    private function endpoint(): string
    {
        return 'https://api.telegram.org/bottest-bot-token/sendMessage';
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
