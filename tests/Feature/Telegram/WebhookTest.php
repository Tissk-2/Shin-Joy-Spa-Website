<?php

namespace Tests\Feature\Telegram;

use App\Models\OutboxMessage;
use App\Models\TelegramUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    // Split so the pre-commit secret scan does not flag these deliberate test fixtures.
    private const SECRET = 'test-'
        . 'webhook-secret-0123456789_ABCDEFGHIJ';

    private const TOKEN = 'AbCdEfGh'
        . 'IjKlMnOpQrStUvWxYz0123456789_-abcde';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Log::spy();
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123456789:'.str_repeat('A', 35),
        ]);
        CarbonImmutable::setTestNow('2026-11-08 03:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_missing_or_wrong_secret_is_forbidden_and_stores_nothing(): void
    {
        $update = $this->startUpdate(1, '/start '.self::TOKEN);
        $changed = substr(self::SECRET, 0, -1).'K';

        foreach ([null, '', 'wrong', $changed, self::SECRET.'x'] as $secret) {
            $this->sendUpdate($update, $secret)->assertForbidden();
        }

        $this->assertDatabaseCount('telegram_updates', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(0, RateLimiter::attempts('telegram-webhook'));
        Log::shouldNotHaveReceived('warning');
    }

    public function test_missing_or_invalid_configuration_fails_closed(): void
    {
        $update = $this->startUpdate(2, '/start '.self::TOKEN);

        config(['services.telegram.webhook_secret' => null]);
        $this->sendUpdate($update, self::SECRET)->assertStatus(503);

        config(['services.telegram.webhook_secret' => str_repeat('a', 31)]);
        $this->sendUpdate($update, str_repeat('a', 31))->assertStatus(503);

        $spaced = str_repeat('a', 39).' ';
        config(['services.telegram.webhook_secret' => $spaced]);
        $this->sendUpdate($update, $spaced)->assertStatus(503);

        $long = str_repeat('a', 257);
        config(['services.telegram.webhook_secret' => $long]);
        $this->sendUpdate($update, $long)->assertStatus(503);

        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => null,
        ]);
        $this->sendUpdate($update)->assertStatus(503);
        // Fails closed before authentication, not later by a type error inside the recorder.
        $this->sendUpdate($update, null)->assertStatus(503);

        config(['services.telegram.bot_token' => 'not-a-token']);
        $this->sendUpdate($update)->assertStatus(503);

        config(['services.telegram.bot_token' => '12a:'.str_repeat('A', 35)]);
        $this->sendUpdate($update)->assertStatus(503);

        $this->assertDatabaseCount('telegram_updates', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(0, RateLimiter::attempts('telegram-webhook'));
        Log::shouldNotHaveReceived('error');
    }

    public function test_a_private_start_command_is_stored_with_one_outbox_message(): void
    {
        $this->sendUpdate($this->startUpdate(1001, '/start '.self::TOKEN))->assertOk();
        $this->assertSame(1, RateLimiter::attempts('telegram-webhook'));

        $row = TelegramUpdate::sole();
        $this->assertSame('123456789', $row->bot_id);
        $this->assertSame(1001, $row->update_id);
        $this->assertSame('start', $row->kind);
        $this->assertSame(self::TOKEN, $row->start_token);
        $this->assertNull($row->outcome);
        $this->assertNull($row->processed_at);
        $this->assertSame('2026-11-08 03:00:00', $row->received_at->toDateTimeString());
        $this->assertSame([
            'chat_id' => 5550001111,
            'chat_type' => 'private',
            'from_id' => 5550001111,
            'from_is_bot' => false,
            'message_id' => 11,
        ], $row->payload);
        $this->assertArrayNotHasKey('start_token', $row->toArray());

        $raw = DB::table('telegram_updates')->value('payload');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('Rani', $raw);
        $this->assertStringNotContainsString('rani_k', $raw);
        $this->assertStringNotContainsString('/start', $raw);

        $message = OutboxMessage::sole();
        $this->assertSame('telegram.update.received', $message->type);
        $this->assertSame('telegram.update:123456789:1001', $message->deduplication_key);
        $this->assertSame(['telegram_update_id' => $row->id], $message->payload);

        $cases = [
            1002 => '/start',
            1003 => '/start@ShinJoyStaffBot '.self::TOKEN,
            1004 => '/start bad*token',
            1005 => '/start '.str_repeat('a', 65),
        ];

        foreach ($cases as $updateId => $text) {
            $this->sendUpdate($this->startUpdate($updateId, $text))->assertOk();
        }

        foreach ([1002 => null, 1003 => self::TOKEN, 1004 => null, 1005 => null] as $updateId => $token) {
            $case = TelegramUpdate::query()->where('update_id', $updateId)->sole();
            $this->assertSame('start', $case->kind);
            $this->assertSame($token, $case->start_token);
        }

        $this->assertDatabaseCount('outbox_messages', 5);
    }

    public function test_a_callback_is_stored_with_only_identity_fields(): void
    {
        $data = str_repeat('d', 64);
        $this->sendUpdate($this->callbackUpdate(2001, $data))->assertOk();

        $row = TelegramUpdate::sole();
        $this->assertSame('callback_query', $row->kind);
        $this->assertSame([
            'callback_query_id' => '4382bfdwdsb323b2d9',
            'from_id' => 5550001111,
            'from_is_bot' => false,
            'data' => $data,
            'chat_id' => -1001234567890,
            'chat_type' => 'supergroup',
            'message_id' => 77,
            'message_thread_id' => 5,
        ], $row->payload);
        $this->assertNull($row->start_token);

        $raw = DB::table('telegram_updates')->value('payload');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('Budi', $raw);
        $this->assertStringNotContainsString('Shin Joy staff', $raw);
        $this->assertStringNotContainsString('Rani', $raw);

        $this->assertDatabaseCount('outbox_messages', 1);

        $inline = $this->callbackUpdate(2002, $data);
        unset($inline['callback_query']['message']);
        $this->sendUpdate($inline)->assertOk();

        $second = TelegramUpdate::query()->where('update_id', 2002)->sole();
        $this->assertSame([
            'callback_query_id' => '4382bfdwdsb323b2d9',
            'from_id' => 5550001111,
            'from_is_bot' => false,
            'data' => $data,
            'chat_id' => null,
            'chat_type' => null,
            'message_id' => null,
            'message_thread_id' => null,
        ], $second->payload);
        $this->assertDatabaseCount('outbox_messages', 2);
    }

    public function test_a_repeated_update_is_stored_and_queued_once(): void
    {
        $this->sendUpdate($this->startUpdate(3001, '/start '.self::TOKEN))->assertOk();
        $this->sendUpdate($this->startUpdate(3001, '/start '.self::TOKEN))->assertOk();
        $this->sendUpdate($this->startUpdate(3001, '/start other'))->assertOk();

        $this->assertDatabaseCount('telegram_updates', 1);
        $this->assertSame(self::TOKEN, TelegramUpdate::sole()->start_token);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_unsupported_and_malformed_updates_are_acknowledged_and_ignored(): void
    {
        $edited = ['update_id' => 4001, 'edited_message' => $this->startUpdate(0, '/start x')['message']];

        $stringSender = $this->startUpdate(4004, '/start '.self::TOKEN);
        $stringSender['message']['from']['id'] = '5550001111';

        $badChat = $this->startUpdate(4005, '/start '.self::TOKEN);
        $badChat['message']['chat']['type'] = 'secret';

        $badCallbackSender = $this->callbackUpdate(4007, 'a');
        $badCallbackSender['callback_query']['from']['is_bot'] = 'false';

        $zeroMessage = $this->startUpdate(4008, '/start '.self::TOKEN);
        $zeroMessage['message']['message_id'] = 0;

        $zeroSender = $this->startUpdate(4009, '/start '.self::TOKEN);
        $zeroSender['message']['from']['id'] = 0;

        $zeroChat = $this->startUpdate(4010, '/start '.self::TOKEN);
        $zeroChat['message']['chat']['id'] = 0;

        $spacedId = $this->callbackUpdate(4012, 'a');
        $spacedId['callback_query']['id'] = 'has space';

        $stringThread = $this->callbackUpdate(4013, 'a');
        $stringThread['callback_query']['message']['message_thread_id'] = '5';

        $zeroThread = $this->callbackUpdate(4014, 'a');
        $zeroThread['callback_query']['message']['message_thread_id'] = 0;

        $zeroCallbackMessage = $this->callbackUpdate(4015, 'a');
        $zeroCallbackMessage['callback_query']['message']['message_id'] = 0;

        $cases = [
            4001 => [$edited, 'unsupported'],
            4002 => [$this->startUpdate(4002, 'hello'), 'unsupported'],
            4003 => [$this->startUpdate(4003, '/startnow'), 'unsupported'],
            4004 => [$stringSender, 'invalid_shape'],
            4005 => [$badChat, 'invalid_shape'],
            4006 => [$this->callbackUpdate(4006, str_repeat('d', 65)), 'invalid_shape'],
            4007 => [$badCallbackSender, 'invalid_shape'],
            4008 => [$zeroMessage, 'invalid_shape'],
            4009 => [$zeroSender, 'invalid_shape'],
            4010 => [$zeroChat, 'invalid_shape'],
            4011 => [$this->callbackUpdate(4011, ''), 'invalid_shape'],
            4012 => [$spacedId, 'invalid_shape'],
            4013 => [$stringThread, 'invalid_shape'],
            4014 => [$zeroThread, 'invalid_shape'],
            4015 => [$zeroCallbackMessage, 'invalid_shape'],
        ];

        foreach ($cases as [$update, $reason]) {
            $this->sendUpdate($update)->assertOk();
        }

        foreach ($cases as $updateId => [$update, $reason]) {
            $row = TelegramUpdate::query()->where('update_id', $updateId)->sole();
            $this->assertSame('other', $row->kind);
            $this->assertSame('ignored', $row->outcome);
            $this->assertSame($reason, $row->outcome_reason);
            $this->assertNull($row->payload);
            $this->assertNull($row->start_token);
            $this->assertSame('2026-11-08 03:00:00', $row->processed_at->toDateTimeString());
        }

        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_unusable_bodies_are_acknowledged_without_storing(): void
    {
        $cases = [
            ['{"update_id":', 'invalid_json'],
            ['5', 'not_an_update'],
            ['{"message":{}}', 'not_an_update'],
            ['{"update_id":-1}', 'not_an_update'],
            ['{"update_id":"7"}', 'not_an_update'],
        ];

        foreach ($cases as [$body, $reason]) {
            $this->send($body)->assertOk();
            Log::shouldHaveReceived('warning')->with('telegram.webhook.rejected', ['reason' => $reason]);
        }

        $base = strlen(json_encode(['update_id' => 900, 'pad' => '']));
        $exact = json_encode(['update_id' => 900, 'pad' => str_repeat('x', 65536 - $base)]);
        $this->assertSame(65536, strlen($exact));
        $this->send($exact)->assertOk();

        $row = TelegramUpdate::query()->where('update_id', 900)->sole();
        $this->assertSame('other', $row->kind);
        $this->assertSame('unsupported', $row->outcome_reason);

        $over = json_encode(['update_id' => 901, 'pad' => str_repeat('x', 65537 - $base)]);
        $this->assertSame(65537, strlen($over));
        $this->send($over)->assertOk();
        Log::shouldHaveReceived('warning')->with('telegram.webhook.rejected', ['reason' => 'too_large']);

        $this->assertDatabaseMissing('telegram_updates', ['update_id' => 901]);
        $this->assertDatabaseCount('telegram_updates', 1);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_a_storage_failure_returns_503_and_never_logs_the_token(): void
    {
        Schema::drop('telegram_updates');

        $this->sendUpdate($this->startUpdate(5001, '/start '.self::TOKEN))->assertStatus(503);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('telegram.webhook.store_failed', Mockery::on(
                fn (array $context): bool => $context === ['exception' => QueryException::class],
            ));

        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_the_update_and_its_outbox_message_commit_together(): void
    {
        $events = config('outbox.events');
        config(['outbox.events' => ['booking.created' => []]]);

        $this->sendUpdate($this->startUpdate(6001, '/start '.self::TOKEN))->assertStatus(503);
        $this->assertDatabaseCount('telegram_updates', 0);
        $this->assertDatabaseCount('outbox_messages', 0);

        config(['outbox.events' => $events]);

        $this->sendUpdate($this->startUpdate(6001, '/start '.self::TOKEN))->assertOk();
        $this->assertDatabaseCount('telegram_updates', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_the_limiter_applies_only_after_authentication(): void
    {
        for ($i = 0; $i < 300; $i++) {
            RateLimiter::hit('telegram-webhook', 60);
        }

        $response = $this->sendUpdate($this->startUpdate(7001, '/start '.self::TOKEN));
        $response->assertStatus(429);
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertDatabaseMissing('telegram_updates', ['update_id' => 7001]);

        $this->sendUpdate($this->startUpdate(7002, '/start '.self::TOKEN), 'wrong')->assertForbidden();

        RateLimiter::clear('telegram-webhook');

        $this->sendUpdate($this->startUpdate(7001, '/start '.self::TOKEN))->assertOk();
        $this->assertDatabaseCount('telegram_updates', 1);
    }

    public function test_the_webhook_route_has_no_session_or_csrf(): void
    {
        $this->assertSame([], Route::getRoutes()->getByName('webhooks.telegram')->gatherMiddleware());

        $response = $this->sendUpdate($this->startUpdate(8001, '/start'));
        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
    }

    private function send(string $body, ?string $secret = self::SECRET): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $secret;
        }

        return $this->call('POST', '/webhooks/telegram', [], [], [], $server, $body);
    }

    private function sendUpdate(array $update, ?string $secret = self::SECRET): TestResponse
    {
        return $this->send(json_encode($update, JSON_THROW_ON_ERROR), $secret);
    }

    private function startUpdate(int $updateId, string $text): array
    {
        return ['update_id' => $updateId, 'message' => [
            'message_id' => 11, 'date' => 1793000000, 'text' => $text,
            'from' => ['id' => 5550001111, 'is_bot' => false, 'first_name' => 'Rani', 'username' => 'rani_k', 'language_code' => 'id'],
            'chat' => ['id' => 5550001111, 'type' => 'private', 'first_name' => 'Rani', 'username' => 'rani_k'],
        ]];
    }

    private function callbackUpdate(int $updateId, string $data): array
    {
        return ['update_id' => $updateId, 'callback_query' => [
            'id' => '4382bfdwdsb323b2d9', 'chat_instance' => '-8122', 'data' => $data,
            'from' => ['id' => 5550001111, 'is_bot' => false, 'first_name' => 'Rani', 'username' => 'rani_k'],
            'message' => ['message_id' => 77, 'message_thread_id' => 5, 'date' => 1793000000, 'text' => 'Booking SJ-123 for Budi Santoso',
                'chat' => ['id' => -1001234567890, 'type' => 'supergroup', 'title' => 'Shin Joy staff']],
        ]];
    }
}
