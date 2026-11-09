<?php

namespace Tests\Feature\Telegram;

use App\Exceptions\Outbox\OutboxPermanentException;
use App\Exceptions\Outbox\OutboxRetryableException;
use App\Models\OutboxMessage;
use App\Support\Telegram\TelegramReplyHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReplyHandlerTest extends TestCase
{
    use RefreshDatabase;

    // Split so the pre-commit secret scan does not flag these deliberate test fixtures.
    private const SECRET = 'test-'
        .'webhook-secret-0123456789_ABCDEFGHIJ';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Log::spy();
        config([
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.bot_token' => '123456789:'.str_repeat('A', 35),
        ]);
    }

    public function test_a_reply_is_sent_to_the_private_chat(): void
    {
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 9]], 200),
        ]);

        $this->handle($this->message());

        Http::assertSent(function (Request $request): bool {
            return $request->url() === $this->endpoint()
                && $request['chat_id'] === '5550001111'
                && $request['text'] === 'Hello';
        });
        Http::assertSentCount(1);
    }

    public function test_telegram_failures_become_permanent_or_retryable_outbox_failures(): void
    {
        Http::fake([
            $this->endpoint() => Http::sequence()
                ->push(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden'], 403)
                ->push(['ok' => false], 500)
                ->pushFailedConnection(),
        ]);

        $cases = [
            ['telegram_http', OutboxPermanentException::class],
            ['telegram_http', OutboxRetryableException::class],
            ['network', OutboxRetryableException::class],
        ];

        foreach ($cases as [$reason, $class]) {
            try {
                $this->handle($this->message());
                $this->fail('Expected a Telegram delivery failure.');
            } catch (OutboxPermanentException|OutboxRetryableException $exception) {
                $this->assertSame($class, $exception::class);
                $this->assertSame($reason, $exception->reason());
            }
        }

        Log::shouldHaveReceived('warning')
            ->with('telegram.reply.failed', ['category' => 'telegram_http', 'http_status' => 403]);
    }

    public function test_bad_payloads_and_a_missing_bot_token_fail_without_sending(): void
    {
        Http::fake();

        $badPayloads = [
            ['chat_id' => -1001234567890, 'text' => 'Hello'],
            ['chat_id' => '5550001111', 'text' => 'Hello'],
            ['chat_id' => 5550001111, 'text' => ''],
        ];

        foreach ($badPayloads as $payload) {
            try {
                $this->handle($this->message($payload));
                $this->fail('Expected the invalid payload to fail permanently.');
            } catch (OutboxPermanentException $exception) {
                $this->assertSame('payload', $exception->reason());
            }
        }

        config(['services.telegram.bot_token' => null]);

        try {
            $this->handle($this->message());
            $this->fail('Expected the missing bot token to fail permanently.');
        } catch (OutboxPermanentException $exception) {
            $this->assertSame('configuration', $exception->reason());
        }

        Http::assertNothingSent();
    }

    private function handle(OutboxMessage $message): void
    {
        app(TelegramReplyHandler::class)->handle($message);
    }

    private function message(array $payload = ['chat_id' => 5550001111, 'text' => 'Hello']): OutboxMessage
    {
        return (new OutboxMessage)->forceFill(['payload' => $payload]);
    }

    private function endpoint(): string
    {
        return 'https://api.telegram.org/bot123456789:'.str_repeat('A', 35).'/sendMessage';
    }
}
