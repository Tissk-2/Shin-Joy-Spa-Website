<?php

namespace Tests\Feature\Support;

use App\Support\TelegramClient;
use App\Support\TelegramDeliveryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Initiated as the Phase 2 RED contract for the Telegram HTTP client (design
// doc section 8 and section 10.C). Phase 5 provides the TelegramClient and
// TelegramDeliveryException implementation, so this suite is now the
// continuing client regression, classification, and security contract. No real
// network access is possible: Http::fake() intercepts every request and
// Http::preventStrayRequests() fails the test if any request escapes the
// registered fakes.
class TelegramClientTest extends TestCase
{
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

        parent::tearDown();
    }

    public function test_successful_send_posts_the_expected_payload_and_returns_the_message_id(): void
    {
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200),
        ]);

        $messageId = (new TelegramClient)->sendMessage(self::MESSAGE, config('services.telegram.staff_chat_id'));

        $this->assertSame(123, $messageId);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === $this->endpoint()
                && $request['chat_id'] === self::CHAT_ID
                && $request['text'] === self::MESSAGE
                && isset($request['link_preview_options'])
                && ($request['link_preview_options']['is_disabled'] ?? null) === true
                && ! array_key_exists('disable_web_page_preview', $request->data())
                && ! array_key_exists('parse_mode', $request->data())
                && ! array_key_exists('message_thread_id', $request->data());
        });
    }

    public function test_send_uses_bounded_connection_and_request_timeouts(): void
    {
        $optionsSeen = [];
        Http::fake(function (Request $request, array $options) use (&$optionsSeen) {
            $optionsSeen = $options;

            return Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200);
        });

        (new TelegramClient)->sendMessage(self::MESSAGE, self::CHAT_ID);

        $this->assertSame(3, $optionsSeen['connect_timeout'] ?? null);
        $this->assertSame(10, $optionsSeen['timeout'] ?? null);
    }

    public function test_send_uses_a_configured_ca_bundle_without_disabling_verification(): void
    {
        config(['services.telegram.ca_bundle' => 'storage/app/private/windows-ca.pem']);
        $optionsSeen = [];
        Http::fake(function (Request $request, array $options) use (&$optionsSeen) {
            $optionsSeen = $options;

            return Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200);
        });

        (new TelegramClient)->sendMessage(self::MESSAGE, self::CHAT_ID);

        $this->assertSame('storage/app/private/windows-ca.pem', $optionsSeen['verify'] ?? null);
    }

    public function test_configured_topic_is_sent_as_an_integer(): void
    {
        config(['services.telegram.message_thread_id' => 77]);
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 124]], 200),
        ]);

        (new TelegramClient)->sendMessage(self::MESSAGE, self::CHAT_ID, 77);

        Http::assertSent(function (Request $request): bool {
            return $request['message_thread_id'] === 77;
        });
    }

    public function test_absent_topic_omits_the_parameter_entirely(): void
    {
        config(['services.telegram.message_thread_id' => null]);
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 125]], 200),
        ]);

        (new TelegramClient)->sendMessage(self::MESSAGE, self::CHAT_ID, null);

        Http::assertSent(function (Request $request): bool {
            return ! array_key_exists('message_thread_id', $request->data());
        });
    }

    public function test_no_bot_token_url_chat_id_or_message_body_is_ever_logged(): void
    {
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 126]], 200),
        ]);

        (new TelegramClient)->sendMessage(self::MESSAGE, self::CHAT_ID);

        // Probe written through the same channel the client would use, proving
        // the capture store is live before the privacy assertions run.
        Log::info('telegram-contract-probe');

        $this->assertLogsContain('telegram-contract-probe');
        $this->assertLogsContainNoneOf([
            'test-bot-token',
            'api.telegram.org',
            self::CHAT_ID,
            self::MESSAGE,
        ]);
    }

    #[DataProvider('permanentStatusProvider')]
    public function test_permanent_http_statuses_fail_without_retry(int $status): void
    {
        Http::fake([$this->endpoint() => Http::response(['ok' => false, 'error_code' => $status], $status)]);

        try {
            (new TelegramClient)->sendMessage(self::MESSAGE, self::CHAT_ID);
            $this->fail('Expected TelegramDeliveryException to be thrown.');
        } catch (TelegramDeliveryException $exception) {
            $this->assertTrue($exception->permanent());
            $this->assertNull($exception->retryAfterSeconds());
            $this->assertSame($status, $exception->httpStatus());
            $this->assertSame($status, $exception->telegramErrorCode());
            $this->assertExceptionIntegrity($exception);
        }
    }

    public static function permanentStatusProvider(): array
    {
        return [[400], [401], [403], [404]];
    }

    #[DataProvider('retryableStatusProvider')]
    public function test_retryable_http_statuses_are_classified_as_retryable(int $status): void
    {
        Http::fake([$this->endpoint() => Http::response(['ok' => false, 'error_code' => $status], $status)]);

        try {
            (new TelegramClient)->sendMessage(self::MESSAGE, self::CHAT_ID);
            $this->fail('Expected TelegramDeliveryException to be thrown.');
        } catch (TelegramDeliveryException $exception) {
            $this->assertFalse($exception->permanent());
            $this->assertNull($exception->retryAfterSeconds());
            $this->assertSame($status, $exception->httpStatus());
            $this->assertSame($status, $exception->telegramErrorCode());
            $this->assertExceptionIntegrity($exception);
        }
    }

    public static function retryableStatusProvider(): array
    {
        return [[408], [500], [503]];
    }

    public function test_connection_failures_are_classified_as_retryable(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        try {
            (new TelegramClient)->sendMessage(self::MESSAGE, self::CHAT_ID);
            $this->fail('Expected TelegramDeliveryException to be thrown.');
        } catch (TelegramDeliveryException $exception) {
            $this->assertFalse($exception->permanent());
            $this->assertNull($exception->retryAfterSeconds());
            $this->assertNull($exception->httpStatus());
            $this->assertNull($exception->telegramErrorCode());
            $this->assertExceptionIntegrity($exception);
        }
    }

    public function test_rate_limit_with_numeric_retry_after_exposes_that_delay(): void
    {
        Http::fake([
            $this->endpoint() => Http::response([
                'ok' => false,
                'error_code' => 429,
                'description' => 'Too Many Requests: retry after 31',
                'parameters' => ['retry_after' => 31],
            ], 429),
        ]);

        try {
            (new TelegramClient)->sendMessage(self::MESSAGE, self::CHAT_ID);
            $this->fail('Expected TelegramDeliveryException to be thrown.');
        } catch (TelegramDeliveryException $exception) {
            $this->assertFalse($exception->permanent());
            $this->assertSame(31, $exception->retryAfterSeconds());
            $this->assertSame(429, $exception->httpStatus());
            $this->assertSame(429, $exception->telegramErrorCode());
            $this->assertExceptionIntegrity($exception);
        }
    }

    #[DataProvider('invalidRetryAfterProvider')]
    public function test_rate_limit_without_valid_retry_after_falls_back_to_normal_backoff(array $dataSet): void
    {
        Http::fake([
            $this->endpoint() => Http::response(
                $dataSet['body'],
                429,
                $dataSet['headers'],
            ),
        ]);

        try {
            (new TelegramClient)->sendMessage(self::MESSAGE, self::CHAT_ID);
            $this->fail('Expected TelegramDeliveryException to be thrown.');
        } catch (TelegramDeliveryException $exception) {
            $this->assertFalse($exception->permanent());
            $this->assertNull($exception->retryAfterSeconds());
            $this->assertSame(429, $exception->httpStatus());
            $this->assertSame(429, $exception->telegramErrorCode());
            $this->assertExceptionIntegrity($exception);
        }
    }

    public static function invalidRetryAfterProvider(): array
    {
        return [
            'missing retry_after' => [['body' => ['ok' => false, 'error_code' => 429], 'headers' => []]],
            'non-numeric string' => [['body' => ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 'soon']], 'headers' => []]],
            'numeric string' => [['body' => ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => '31']], 'headers' => []]],
            'float' => [['body' => '{"ok":false,"error_code":429,"parameters":{"retry_after":31.0}}', 'headers' => ['Content-Type' => 'application/json']]],
            'true' => [['body' => ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => true]], 'headers' => []]],
            'false' => [['body' => ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => false]], 'headers' => []]],
            'zero' => [['body' => ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 0]], 'headers' => []]],
            'negative integer' => [['body' => ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => -5]], 'headers' => []]],
        ];
    }

    // An HTTP 200 response is a success only when the JSON body has ok === true
    // and an integer result.message_id. Every malformed success shape below
    // must produce a sanitized retryable TelegramDeliveryException, must not
    // return a message ID, and must not leak response content into logs.
    // (The provider returns raw data only: PHPUnit evaluates providers before
    // the application boots, so the Http facade must not be touched there.)
    #[DataProvider('malformedSuccessResponseProvider')]
    public function test_malformed_success_responses_are_retryable_and_sanitized(array $dataSet): void
    {
        Http::fake([
            $this->endpoint() => Http::response($dataSet['body'], 200, $dataSet['headers']),
        ]);

        $messageId = null;

        try {
            $messageId = (new TelegramClient)->sendMessage(self::MESSAGE, self::CHAT_ID);
            $this->fail('Expected TelegramDeliveryException to be thrown.');
        } catch (TelegramDeliveryException $exception) {
            $this->assertFalse($exception->permanent());
            $this->assertNull($exception->retryAfterSeconds());
            $this->assertSame(200, $exception->httpStatus());
            $this->assertNull($exception->telegramErrorCode());
            $this->assertExceptionIntegrity($exception);
        }

        $this->assertNull($messageId);
        $this->assertLogsContainNoneOf([
            'test-bot-token',
            'api.telegram.org',
            self::CHAT_ID,
            self::MESSAGE,
            'message_id',
            'gateway',
        ]);
    }

    public static function malformedSuccessResponseProvider(): array
    {
        return [
            'http 200 with ok=false' => [['body' => ['ok' => false], 'headers' => []]],
            'http 200 with result missing' => [['body' => ['ok' => true], 'headers' => []]],
            'http 200 with message_id missing' => [['body' => ['ok' => true, 'result' => []], 'headers' => []]],
            'http 200 with non-integer message_id' => [['body' => ['ok' => true, 'result' => ['message_id' => '123']], 'headers' => []]],
            'http 200 with non-json body' => [['body' => '<html>gateway error</html>', 'headers' => ['Content-Type' => 'text/html']]],
        ];
    }

    public function test_failures_never_leak_secrets_urls_or_message_content_into_logs(): void
    {
        Http::fake([
            $this->endpoint() => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401),
        ]);

        try {
            (new TelegramClient)->sendMessage(self::MESSAGE, self::CHAT_ID);
        } catch (TelegramDeliveryException) {
            // Classified above; this test only inspects what was logged.
        }

        Log::info('telegram-contract-probe');

        $this->assertLogsContain('telegram-contract-probe');
        $this->assertLogsContainNoneOf([
            'test-bot-token',
            'api.telegram.org',
            self::CHAT_ID,
            self::MESSAGE,
            'Unauthorized',
        ]);
    }

    private function endpoint(): string
    {
        return 'https://api.telegram.org/bottest-bot-token/sendMessage';
    }

    private function assertExceptionIntegrity(TelegramDeliveryException $exception): void
    {
        $this->assertSame('Telegram delivery failed.', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
    }

    private function configureTelegram(): void
    {
        config([
            'services.telegram.booking_notifications_enabled' => true,
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.staff_chat_id' => self::CHAT_ID,
            'services.telegram.message_thread_id' => null,
            'services.telegram.ca_bundle' => null,
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

    private function assertLogsContain(string $needle): void
    {
        $found = false;

        foreach ($this->logs->lines as $line) {
            if (str_contains($line, $needle)) {
                $found = true;
            }
        }

        $this->assertTrue($found, "Log output was expected to contain [{$needle}].");
    }
}
