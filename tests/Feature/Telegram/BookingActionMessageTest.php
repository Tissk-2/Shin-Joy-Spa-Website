<?php

namespace Tests\Feature\Telegram;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\TelegramActionToken;
use App\Models\TelegramBookingMessage;
use App\Support\Telegram\BookingActionKeyboard;
use App\Support\TelegramBookingSender;
use App\Support\TelegramDeliveryException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingActionMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-09 03:00:00 UTC');
        Http::preventStrayRequests();

        config([
            'services.telegram.booking_notifications_enabled' => true,
            'services.telegram.actions_enabled' => true,
            'services.telegram.bot_token' => '123456789:'.str_repeat('A', 35),
            'services.telegram.staff_chat_id' => '-1001234567890',
            'app.url' => 'https://spa.example.com',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_pending_booking_is_sent_with_confirm_decline_and_view_buttons(): void
    {
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200),
        ]);

        $booking = $this->booking();

        $this->assertSame(123, $this->send($booking));

        $markups = $this->sentMarkups();
        $this->assertCount(1, $markups);

        $keyboard = $markups[0]['inline_keyboard'] ?? null;
        $this->assertIsArray($keyboard);
        $this->assertCount(2, $keyboard);

        $this->assertCount(1, $keyboard[0]);
        $this->assertSame(BookingActionKeyboard::CONFIRM_LABEL, $keyboard[0][0]['text']);
        $this->assertMatchesRegularExpression('/\At:[A-Za-z0-9_-]{43}\z/', $keyboard[0][0]['callback_data']);

        $this->assertCount(2, $keyboard[1]);
        $this->assertSame('Decline', $keyboard[1][0]['text']);
        $this->assertMatchesRegularExpression('/\At:[A-Za-z0-9_-]{43}\z/', $keyboard[1][0]['callback_data']);
        $this->assertSame('View booking', $keyboard[1][1]['text']);
        $this->assertSame('https://spa.example.com/admin/bookings/'.$booking->reference, $keyboard[1][1]['url']);

        $this->assertLessThanOrEqual(64, strlen($keyboard[0][0]['callback_data']));
        $this->assertLessThanOrEqual(64, strlen($keyboard[1][0]['callback_data']));

        $message = TelegramBookingMessage::query()->sole();
        $this->assertSame($booking->id, $message->booking_id);
        $this->assertSame('-1001234567890', $message->chat_id);
        $this->assertNull($message->message_thread_id);
        $this->assertSame(123, $message->message_id);
        $this->assertSame(1, $message->booking_version);
        $this->assertSame('sent', $message->state);
        $this->assertSame('2026-11-09 03:00:00', $message->sent_at->toDateTimeString());

        $tokens = TelegramActionToken::query()->orderBy('id')->get();
        $this->assertCount(2, $tokens);
        $this->assertSame(['approve', 'decline_prompt'], $tokens->pluck('action')->all());

        foreach ($tokens as $token) {
            $this->assertSame($message->id, $token->telegram_booking_message_id);
            $this->assertSame($booking->id, $token->booking_id);
            $this->assertSame(1, $token->booking_version);
            $this->assertSame('2026-11-20 03:30:00', $token->expires_at->toDateTimeString());
            $this->assertNull($token->consumed_at);
            $this->assertNull($token->initiating_user_id);
            $this->assertNull($token->initiating_telegram_user_id);
        }

        $approveNonce = $this->nonce($keyboard[0][0]['callback_data']);
        $declineNonce = $this->nonce($keyboard[1][0]['callback_data']);

        $rawHashes = DB::table('telegram_action_tokens')->orderBy('id')->pluck('nonce_hash')->all();
        $this->assertSame(hash('sha256', $approveNonce), $rawHashes[0]);
        $this->assertSame(hash('sha256', $declineNonce), $rawHashes[1]);

        $serialized = json_encode(DB::table('telegram_action_tokens')->get());
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString($approveNonce, $serialized);
        $this->assertStringNotContainsString($declineNonce, $serialized);
    }

    public function test_buttons_are_left_out_when_actions_are_off_or_cannot_apply(): void
    {
        Http::fake([
            $this->endpoint() => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 201]], 200)
                ->push(['ok' => true, 'result' => ['message_id' => 202]], 200)
                ->push(['ok' => true, 'result' => ['message_id' => 203]], 200)
                ->push(['ok' => true, 'result' => ['message_id' => 204]], 200)
                ->push(['ok' => true, 'result' => ['message_id' => 205]], 200)
                ->push(['ok' => true, 'result' => ['message_id' => 206]], 200),
        ]);

        config(['services.telegram.actions_enabled' => false]);
        $actionsOff = $this->booking();
        $this->assertSame(201, $this->send($actionsOff));
        config(['services.telegram.actions_enabled' => true]);

        $confirmed = $this->booking(['status' => BookingStatus::Confirmed]);
        $this->assertSame(202, $this->send($confirmed));

        $startsNow = $this->booking(['starts_at' => '2026-11-09 03:00:00']);
        $this->assertSame(203, $this->send($startsNow));

        $noStart = $this->booking(['starts_at' => null]);
        $this->assertSame(204, $this->send($noStart));

        config(['services.telegram.staff_chat_id' => '@shinjoystaff']);
        $usernameChat = $this->booking();
        $this->assertSame(205, $this->send($usernameChat));
        config(['services.telegram.staff_chat_id' => '-1001234567890']);

        config(['services.telegram.actions_enabled' => '1']);
        $stringEnabled = $this->booking();
        $this->assertSame(206, $this->send($stringEnabled));

        $this->assertSame([null, null, null, null, null, null], $this->sentMarkups());

        foreach ([$actionsOff, $confirmed, $startsNow, $noStart, $usernameChat, $stringEnabled] as $booking) {
            $this->assertSame(
                'sent',
                TelegramBookingMessage::query()->where('booking_id', $booking->id)->sole()->state,
            );
        }

        $this->assertSame(
            '@shinjoystaff',
            TelegramBookingMessage::query()->where('booking_id', $usernameChat->id)->sole()->chat_id,
        );

        $this->assertSame(0, TelegramActionToken::query()->count());
    }

    public function test_the_view_button_needs_an_https_site_url(): void
    {
        config(['app.url' => 'http://localhost']);
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200),
        ]);

        $booking = $this->booking();
        $this->assertSame(123, $this->send($booking));

        $keyboard = $this->sentMarkups()[0]['inline_keyboard'] ?? null;
        $this->assertIsArray($keyboard);
        $this->assertCount(2, $keyboard);
        $this->assertSame(BookingActionKeyboard::CONFIRM_LABEL, $keyboard[0][0]['text']);
        $this->assertCount(1, $keyboard[1]);
        $this->assertSame('Decline', $keyboard[1][0]['text']);
    }

    public function test_a_failed_send_is_retried_with_fresh_buttons_and_never_sent_twice(): void
    {
        Http::fake([
            $this->endpoint() => Http::sequence()
                ->push(['ok' => false, 'error_code' => 500], 500)
                ->push(['ok' => true, 'result' => ['message_id' => 123]], 200),
        ]);

        $booking = $this->booking();

        try {
            $this->send($booking);
            $this->fail('Expected TelegramDeliveryException to be thrown.');
        } catch (TelegramDeliveryException) {
            // The outbox owns the retry; this test drives the second attempt.
        }

        $record = TelegramBookingMessage::query()->sole();
        $this->assertSame('sending', $record->state);
        $this->assertNull($record->message_id);
        $this->assertSame(2, TelegramActionToken::query()->count());
        $first = DB::table('telegram_action_tokens')->orderBy('id')->pluck('nonce_hash')->all();

        $this->assertSame(123, $this->send($booking));

        $second = DB::table('telegram_action_tokens')->orderBy('id')->pluck('nonce_hash')->all();
        $this->assertCount(2, $second);
        $this->assertSame([], array_intersect($first, $second));

        $markups = $this->sentMarkups();
        $this->assertCount(2, $markups);
        $secondKeyboard = $markups[1]['inline_keyboard'] ?? null;
        $this->assertIsArray($secondKeyboard);
        $this->assertSame(
            hash('sha256', $this->nonce($secondKeyboard[0][0]['callback_data'])),
            $second[0],
        );
        $this->assertSame(
            hash('sha256', $this->nonce($secondKeyboard[1][0]['callback_data'])),
            $second[1],
        );

        $record->refresh();
        $this->assertSame('sent', $record->state);
        $this->assertSame(123, $record->message_id);

        $this->assertSame(123, $this->send($booking));

        Http::assertSentCount(2);
        $this->assertSame(
            $second,
            DB::table('telegram_action_tokens')->orderBy('id')->pluck('nonce_hash')->all(),
        );
    }

    public function test_an_overlapping_attempt_never_leaves_the_row_on_a_copy_with_dead_buttons(): void
    {
        $booking = $this->booking();
        $calls = 0;
        Http::fake(function () use (&$calls, $booking) {
            $calls++;
            if ($calls === 1) {
                // A second worker re-leases the delivery while the first still waits on Telegram.
                $this->assertSame(222, $this->send($booking));

                return Http::response(['ok' => true, 'result' => ['message_id' => 111]], 200);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 222]], 200);
        });

        $this->assertSame(111, $this->send($booking));

        $record = TelegramBookingMessage::query()->sole();
        $this->assertSame(222, $record->message_id);
        $this->assertSame('sent', $record->state);

        $markupFor = fn (int $messageId): array => Http::recorded()
            ->first(fn (array $pair): bool => $pair[1]->json('result.message_id') === $messageId)[0]->data()['reply_markup'];
        $live = DB::table('telegram_action_tokens')->orderBy('id')->pluck('nonce_hash')->all();
        $this->assertSame([
            hash('sha256', $this->nonce($markupFor(222)['inline_keyboard'][0][0]['callback_data'])),
            hash('sha256', $this->nonce($markupFor(222)['inline_keyboard'][1][0]['callback_data'])),
        ], $live);
        $this->assertNotContains(hash('sha256', $this->nonce($markupFor(111)['inline_keyboard'][0][0]['callback_data'])), $live);
    }

    public function test_the_view_button_is_left_out_for_hosts_telegram_refuses(): void
    {
        $urls = ['https://localhost', 'https://127.0.0.1:8443', 'https://spa.localhost', 'https://[::1]'];
        Http::fake([
            $this->endpoint() => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 301]], 200)
                ->push(['ok' => true, 'result' => ['message_id' => 302]], 200)
                ->push(['ok' => true, 'result' => ['message_id' => 303]], 200)
                ->push(['ok' => true, 'result' => ['message_id' => 304]], 200),
        ]);

        foreach ($urls as $url) {
            config(['app.url' => $url]);
            $this->send($this->booking());
        }

        foreach ($this->sentMarkups() as $markup) {
            $this->assertCount(1, $markup['inline_keyboard'][1]);
            $this->assertSame('Decline', $markup['inline_keyboard'][1][0]['text']);
        }
        $this->assertCount(4, $this->sentMarkups());
    }

    public function test_a_non_canonical_chat_id_gets_no_buttons(): void
    {
        config(['services.telegram.staff_chat_id' => '-00100123']);
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200),
        ]);

        $this->send($this->booking());

        $this->assertSame([null], $this->sentMarkups());
        $this->assertSame(0, TelegramActionToken::query()->count());
    }

    public function test_the_topic_and_booking_version_are_bound(): void
    {
        config(['services.telegram.message_thread_id' => 77]);
        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200),
        ]);

        $this->send($this->booking(['booking_version' => 3]));

        $this->assertSame(77, Http::recorded()->first()[0]->data()['message_thread_id']);
        $record = TelegramBookingMessage::query()->sole();
        $this->assertSame(77, $record->message_thread_id);
        $this->assertSame(3, $record->booking_version);
        $this->assertSame([3, 3], TelegramActionToken::query()->orderBy('id')->pluck('booking_version')->all());
    }

    public function test_consumed_tokens_survive_a_re_render(): void
    {
        Http::fake([
            $this->endpoint() => Http::sequence()
                ->push(['ok' => false, 'error_code' => 500], 500)
                ->push(['ok' => true, 'result' => ['message_id' => 123]], 200),
        ]);
        $booking = $this->booking();

        try {
            $this->send($booking);
            $this->fail('Expected TelegramDeliveryException to be thrown.');
        } catch (TelegramDeliveryException) {
        }
        DB::table('telegram_action_tokens')->where('action', 'approve')
            ->update(['consumed_at' => '2026-11-09 03:00:00', 'result' => 'approved']);

        $this->send($booking);

        $this->assertSame(3, TelegramActionToken::query()->count());
        $this->assertSame(1, TelegramActionToken::query()->where('result', 'approved')->count());
    }

    private function endpoint(): string
    {
        return 'https://api.telegram.org/bot123456789:'.str_repeat('A', 35).'/sendMessage';
    }

    /** @param  array<string, mixed>  $overrides */
    private function booking(array $overrides = []): Booking
    {
        return Booking::factory()->create(['starts_at' => '2026-11-20 03:30:00', ...$overrides])->fresh();
    }

    private function send(Booking $booking): int
    {
        return $this->app->make(TelegramBookingSender::class)->send($booking);
    }

    /** @return list<array<string, mixed>|null> */
    private function sentMarkups(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): ?array => $pair[0]->data()['reply_markup'] ?? null)
            ->all();
    }

    private function nonce(string $callbackData): string
    {
        return substr($callbackData, 2);
    }
}
