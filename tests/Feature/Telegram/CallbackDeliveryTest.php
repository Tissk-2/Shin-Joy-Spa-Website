<?php

namespace Tests\Feature\Telegram;

use App\Exceptions\Outbox\OutboxPermanentException;
use App\Exceptions\Outbox\OutboxRetryableException;
use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Models\TelegramActionToken;
use App\Models\TelegramBookingMessage;
use App\Support\Telegram\TelegramCallbackAnswerHandler;
use App\Support\Telegram\TelegramDeclinePromptHandler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CallbackDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Log::spy();
        config(['services.telegram.bot_token' => '123456789:'.str_repeat('A', 35)]);
        CarbonImmutable::setTestNow('2026-11-09 03:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_callback_answer_is_sent_and_failures_map_to_outbox_failures(): void
    {
        $url = $this->endpoint('answerCallbackQuery');
        Http::fake([
            $url => Http::sequence()
                ->push(['ok' => true, 'result' => true], 200)
                ->push(['ok' => false, 'error_code' => 400], 400)
                ->push(['ok' => false], 500)
                ->pushFailedConnection(),
        ]);

        $handler = app(TelegramCallbackAnswerHandler::class);
        $handler->handle($this->answer());

        Http::assertSent(function (Request $request) use ($url): bool {
            return $request->url() === $url
                && $request['callback_query_id'] === 'cbq-1'
                && $request['text'] === 'Booking confirmed.'
                && $request['show_alert'] === false;
        });

        $cases = [
            ['telegram_http', OutboxPermanentException::class],
            ['telegram_http', OutboxRetryableException::class],
            ['network', OutboxRetryableException::class],
        ];

        foreach ($cases as [$reason, $class]) {
            try {
                $handler->handle($this->answer());
                $this->fail('Expected a Telegram delivery failure.');
            } catch (OutboxPermanentException|OutboxRetryableException $exception) {
                $this->assertSame($class, $exception::class);
                $this->assertSame($reason, $exception->reason());
            }
        }

        Log::shouldHaveReceived('warning')
            ->with('telegram.callback_answer.failed', ['category' => 'telegram_http', 'http_status' => 400]);

        Http::assertSentCount(4);

        foreach ([
            ['callback_query_id' => '', 'text' => 'Booking confirmed.', 'show_alert' => false],
            ['callback_query_id' => 'cbq-1', 'text' => '', 'show_alert' => false],
            ['callback_query_id' => 'cbq-1', 'text' => 'Booking confirmed.', 'show_alert' => 'false'],
        ] as $payload) {
            try {
                $handler->handle($this->answer($payload));
                $this->fail('Expected the invalid payload to fail permanently.');
            } catch (OutboxPermanentException $exception) {
                $this->assertSame('payload', $exception->reason());
            }
        }

        Http::assertSentCount(4);
    }

    public function test_a_decline_prompt_is_sent_with_its_button_only_while_it_is_live(): void
    {
        $url = $this->endpoint('sendMessage');
        Http::fake([
            $url => Http::response(['ok' => true, 'result' => ['message_id' => 124]], 200),
        ]);

        $nonce = str_repeat('b', 43);
        $token = $this->liveConfirmToken($nonce);
        $payload = $this->promptPayload($nonce);

        $handler = app(TelegramDeclinePromptHandler::class);
        $handler->handle($this->prompt($payload));

        Http::assertSent(function (Request $request) use ($url, $nonce): bool {
            $data = $request->data();

            return $request->url() === $url
                && $data['chat_id'] === '-1001234567890'
                && $data['message_thread_id'] === 77
                && $data['text'] === 'Rani: decline booking SJ-1? Only you can confirm, within 5 minutes.'
                && ($data['reply_markup'] ?? null) === ['inline_keyboard' => [[
                    ['text' => 'Yes, decline', 'callback_data' => 't:'.$nonce],
                ]]];
        });

        $token->forceFill(['consumed_at' => CarbonImmutable::now('UTC')])->save();
        $handler->handle($this->prompt($payload));
        Http::assertSentCount(1);

        DB::table('telegram_action_tokens')->where('id', $token->id)->update(['consumed_at' => null]);
        CarbonImmutable::setTestNow('2026-11-09 03:05:00 UTC');
        $handler->handle($this->prompt($payload));
        Http::assertSentCount(1);

        try {
            $handler->handle($this->prompt(['nonce' => 'not-encrypted'] + $payload));
            $this->fail('Expected the bad ciphertext to fail permanently.');
        } catch (OutboxPermanentException $exception) {
            $this->assertSame('payload', $exception->reason());
        }
    }

    public function test_a_failed_or_malformed_decline_prompt_maps_to_outbox_failures(): void
    {
        Http::fake([
            $this->endpoint('sendMessage') => Http::sequence()
                ->push(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden'], 403)
                ->push(['ok' => false], 500)
                ->pushFailedConnection(),
        ]);
        $nonce = str_repeat('c', 43);
        $this->liveConfirmToken($nonce);
        $handler = app(TelegramDeclinePromptHandler::class);

        $cases = [
            ['telegram_http', OutboxPermanentException::class],
            ['telegram_http', OutboxRetryableException::class],
            ['network', OutboxRetryableException::class],
        ];
        foreach ($cases as [$reason, $class]) {
            try {
                $handler->handle($this->prompt($this->promptPayload($nonce)));
                $this->fail('Expected a Telegram delivery failure.');
            } catch (OutboxPermanentException|OutboxRetryableException $exception) {
                $this->assertSame($class, $exception::class);
                $this->assertSame($reason, $exception->reason());
            }
        }
        Log::shouldHaveReceived('warning')
            ->with('telegram.decline_prompt.failed', ['category' => 'telegram_http', 'http_status' => 403]);
        Http::assertSentCount(3);

        foreach ([
            ['message_thread_id' => '77'],
            ['chat_id' => ''],
            ['text' => ''],
            ['nonce' => 123],
        ] as $broken) {
            try {
                $handler->handle($this->prompt($broken + $this->promptPayload($nonce)));
                $this->fail('Expected the invalid payload to fail permanently.');
            } catch (OutboxPermanentException $exception) {
                $this->assertSame('payload', $exception->reason());
            }
        }
        Http::assertSentCount(3);
    }

    public function test_both_delivery_handlers_refuse_a_missing_bot_token_without_sending(): void
    {
        Http::fake();
        $nonce = str_repeat('d', 43);
        $this->liveConfirmToken($nonce);
        config(['services.telegram.bot_token' => null]);

        foreach ([
            [app(TelegramCallbackAnswerHandler::class), $this->answer()],
            [app(TelegramDeclinePromptHandler::class), $this->prompt($this->promptPayload($nonce))],
        ] as [$handler, $message]) {
            try {
                $handler->handle($message);
                $this->fail('Expected a missing bot token to fail permanently.');
            } catch (OutboxPermanentException $exception) {
                $this->assertSame('configuration', $exception->reason());
            }
        }

        Http::assertNothingSent();
    }

    private function liveConfirmToken(string $nonce): TelegramActionToken
    {
        $booking = Booking::factory()->create(['starts_at' => '2026-11-20 03:30:00']);
        $row = TelegramBookingMessage::query()->create([
            'booking_id' => $booking->id,
            'chat_id' => '-1001234567890',
            'message_thread_id' => null,
            'message_id' => 123,
            'booking_version' => 1,
            'state' => 'sent',
            'sent_at' => CarbonImmutable::now('UTC'),
        ]);

        return TelegramActionToken::query()->create([
            'telegram_booking_message_id' => $row->id,
            'booking_id' => $booking->id,
            'booking_version' => 1,
            'action' => 'decline_confirm',
            'nonce_hash' => hash('sha256', $nonce),
            'expires_at' => '2026-11-09 03:05:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function promptPayload(string $nonce): array
    {
        return [
            'chat_id' => '-1001234567890',
            'message_thread_id' => 77,
            'text' => 'Rani: decline booking SJ-1? Only you can confirm, within 5 minutes.',
            'nonce' => Crypt::encryptString($nonce),
        ];
    }

    private function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot123456789:'.str_repeat('A', 35).'/'.$method;
    }

    /** @param  array<string, mixed>  $payload */
    private function answer(array $payload = ['callback_query_id' => 'cbq-1', 'text' => 'Booking confirmed.', 'show_alert' => false]): OutboxMessage
    {
        return (new OutboxMessage)->forceFill(['payload' => $payload]);
    }

    /** @param  array<string, mixed>  $payload */
    private function prompt(array $payload): OutboxMessage
    {
        return (new OutboxMessage)->forceFill(['payload' => $payload]);
    }
}
