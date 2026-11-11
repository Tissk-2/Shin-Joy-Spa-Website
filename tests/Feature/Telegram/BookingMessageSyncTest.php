<?php

namespace Tests\Feature\Telegram;

use App\Actions\ApproveBooking;
use App\Actions\CancelBooking;
use App\Enums\BookingStatus;
use App\Exceptions\Outbox\OutboxPermanentException;
use App\Exceptions\Outbox\OutboxRetryableException;
use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Models\TelegramActionToken;
use App\Models\TelegramBookingMessage;
use App\Models\User;
use App\Support\ActionContext;
use App\Support\Outbox\BookingCreatedOutbox;
use App\Support\Outbox\Handlers\TelegramBookingNotificationHandler;
use App\Support\TelegramBookingSender;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingMessageSyncTest extends TestCase
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

    public function test_a_decision_edits_the_message_to_the_latest_status_and_removes_the_buttons(): void
    {
        Http::fake([
            $this->endpoint('sendMessage') => $this->ok(123),
            $this->endpoint('editMessageText') => $this->ok(123),
        ]);

        $booking = $this->booking();
        app(TelegramBookingSender::class)->send($booking);
        $this->approve($booking, $this->staff());

        $this->handle($this->event('booking.approved', $booking));

        $edits = $this->edits();
        $this->assertCount(1, $edits);
        $request = $edits[0];
        $this->assertSame('-1001234567890', $request['chat_id']);
        $this->assertSame(123, $request['message_id']);
        $this->assertStringContainsString('Status: Confirmed', (string) $request['text']);
        $this->assertStringNotContainsString('Status: Pending', (string) $request['text']);
        $this->assertSame(['inline_keyboard' => []], $request['reply_markup']);
        $this->assertSame(['is_disabled' => true], $request['link_preview_options']);

        $record = TelegramBookingMessage::query()->sole();
        $this->assertSame(2, $record->booking_version);
        $this->assertSame('sent', $record->state);
        $this->assertSame(0, TelegramActionToken::query()->whereNull('consumed_at')->count());
        Http::assertSentCount(2);

        $this->assertSame(
            ['telegram', 'customer'],
            $this->event('booking.approved', $booking)->deliveries()->orderBy('id')->pluck('handler_key')->all(),
        );
    }

    public function test_an_unchanged_message_counts_as_success(): void
    {
        Http::fake([
            $this->endpoint('sendMessage') => $this->ok(123),
            $this->endpoint('editMessageText') => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same as a current content and reply markup of the message',
            ], 400),
        ]);

        $booking = $this->booking();
        app(TelegramBookingSender::class)->send($booking);
        $this->approve($booking, $this->staff());

        $this->handle($this->event('booking.approved', $booking));

        $this->assertSame(2, TelegramBookingMessage::query()->sole()->booking_version);
    }

    public function test_an_overtaken_refresh_renders_and_edits_again(): void
    {
        $booking = $this->booking();
        $editCalls = 0;
        $sendCalls = 0;

        Http::fake(function (Request $request) use (&$editCalls, &$sendCalls, $booking) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $sendCalls++;

                return $this->ok(123);
            }

            if (! str_ends_with($request->url(), '/editMessageText')) {
                $this->fail('Unexpected Telegram request.');
            }

            $editCalls++;
            if ($editCalls === 1) {
                // A completion commits while the first worker's edit is in flight.
                DB::table('bookings')->where('id', $booking->id)->update([
                    'status' => 'completed',
                    'booking_version' => 3,
                ]);
                $this->assertTrue(app(TelegramBookingSender::class)->refresh($booking));
            }

            return $this->ok(124);
        });

        app(TelegramBookingSender::class)->send($booking);
        $this->approve($booking, $this->staff());

        $this->assertTrue(app(TelegramBookingSender::class)->refresh($booking));

        $edits = $this->edits();
        $this->assertCount(3, $edits);
        $this->assertStringContainsString('Status: Completed', (string) $edits[2]['text']);
        $this->assertSame(3, TelegramBookingMessage::query()->sole()->booking_version);
        $this->assertSame(1, $sendCalls);
    }

    public function test_a_deleted_message_is_marked_and_never_edited_again(): void
    {
        Http::fake([
            $this->endpoint('sendMessage') => $this->ok(123),
            $this->endpoint('editMessageText') => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: message to edit not found',
            ], 400),
        ]);

        $staff = $this->staff();
        $booking = $this->booking();
        app(TelegramBookingSender::class)->send($booking);
        $this->approve($booking, $staff);

        $this->handle($this->event('booking.approved', $booking));

        $record = TelegramBookingMessage::query()->sole();
        $this->assertNull($record->message_id);
        $this->assertSame('deleted', $record->state);
        Http::assertSentCount(2);

        (new CancelBooking)($staff, $booking->id, BookingStatus::Confirmed, null, new ActionContext('dashboard'));
        $this->handle($this->event('booking.cancelled', $booking));

        Http::assertSentCount(2);
    }

    public function test_a_booking_without_a_recorded_message_gets_no_telegram_call(): void
    {
        $booking = $this->booking();
        $this->approve($booking, $this->staff());

        $this->handle($this->event('booking.approved', $booking));

        Http::assertNothingSent();
        $this->assertSame(0, TelegramBookingMessage::query()->count());
    }

    public function test_a_failed_edit_maps_to_outbox_failures(): void
    {
        Http::fake([
            $this->endpoint('sendMessage') => $this->ok(123),
            $this->endpoint('editMessageText') => Http::sequence()
                ->push(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was kicked'], 403)
                ->push(['ok' => false], 500)
                ->push(['ok' => false, 'error_code' => 400, 'description' => "Bad Request: message can't be edited"], 400),
        ]);

        $booking = $this->booking();
        app(TelegramBookingSender::class)->send($booking);
        $this->approve($booking, $this->staff());
        $event = $this->event('booking.approved', $booking);
        $record = TelegramBookingMessage::query()->sole();

        foreach ([
            [OutboxPermanentException::class, 'telegram_http'],
            [OutboxRetryableException::class, 'telegram_http'],
            [OutboxPermanentException::class, 'telegram_http'],
        ] as [$expected, $reason]) {
            try {
                $this->handle($event);
                $this->fail('Expected an outbox failure.');
            } catch (OutboxPermanentException|OutboxRetryableException $exception) {
                $this->assertInstanceOf($expected, $exception);
                $this->assertSame($reason, $exception->reason());
            }

            $record->refresh();
            $this->assertSame(123, $record->message_id);
            $this->assertSame('sent', $record->state);
        }
    }

    public function test_still_overtaken_after_three_rounds_retries_later(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                return $this->ok(123);
            }

            // Every render is replaced by another worker before it can record its version.
            DB::table('telegram_booking_messages')->update(['render_key' => 'another-worker']);

            return $this->ok(124);
        });

        $booking = $this->booking();
        app(TelegramBookingSender::class)->send($booking);
        $this->approve($booking, $this->staff());

        try {
            $this->handle($this->event('booking.approved', $booking));
            $this->fail('Expected a retryable outbox failure.');
        } catch (OutboxRetryableException $exception) {
            $this->assertSame('overtaken', $exception->reason());
        }

        $this->assertCount(3, $this->edits());
    }

    public function test_a_decision_made_while_the_first_message_is_in_flight_still_reaches_it(): void
    {
        $booking = $this->booking();
        $staff = $this->staff();
        $created = $this->created($booking);
        $decided = false;
        Http::fake(function (Request $request) use ($booking, $staff, &$decided) {
            if (str_ends_with($request->url(), '/sendMessage') && ! $decided) {
                $decided = true;
                // A colleague approves in the dashboard while the first message is in flight. Its refresh finds no
                // recorded message yet, so it has nothing to edit.
                $this->approve($booking, $staff);
                $this->handle($this->event('booking.approved', $booking));
            }

            return $this->ok(123);
        });

        $this->handle($created);

        $edits = $this->edits();
        $this->assertCount(1, $edits);
        $this->assertStringContainsString('Status: Confirmed', (string) $edits[0]['text']);
        $this->assertSame(['inline_keyboard' => []], $edits[0]['reply_markup']);
        $this->assertSame(0, TelegramActionToken::query()->whereNull('consumed_at')->count());
        $this->assertSame(2, TelegramBookingMessage::query()->sole()->booking_version);
    }

    public function test_a_retried_first_send_catches_up_with_a_decision_it_missed(): void
    {
        $booking = $this->booking();
        $created = $this->created($booking);
        Http::fake([
            $this->endpoint('sendMessage') => Http::response(['ok' => false], 500),
            $this->endpoint('editMessageText') => $this->ok(123),
        ]);

        // Telegram accepted copy 123, but the response was lost.
        try {
            $this->handle($created);
            $this->fail('Expected a retryable outbox failure.');
        } catch (OutboxRetryableException) {
        }
        $this->approve($booking, $this->staff());
        $this->handle($this->event('booking.approved', $booking));
        // A press on copy 123 records it (ProcessBookingCallback, C3b); the retried send then finds it delivered.
        DB::table('telegram_booking_messages')->update(['message_id' => 123, 'state' => 'sent']);

        $this->handle($created);

        $edits = $this->edits();
        $this->assertCount(1, $edits);
        $this->assertStringContainsString('Status: Confirmed', (string) $edits[0]['text']);
        $this->assertSame(0, TelegramActionToken::query()->whereNull('consumed_at')->count());
    }

    public function test_a_routine_first_message_makes_no_extra_edit(): void
    {
        $booking = $this->booking();
        Http::fake([$this->endpoint('sendMessage') => $this->ok(123)]);

        $this->handle($this->created($booking));

        Http::assertSentCount(1);
        $this->assertSame(1, TelegramBookingMessage::query()->sole()->booking_version);
    }

    public function test_an_overtaken_catch_up_renders_and_edits_again(): void
    {
        $booking = $this->booking();
        $staff = $this->staff();
        $created = $this->created($booking);
        $decided = false;
        $editCalls = 0;
        Http::fake(function (Request $request) use ($booking, $staff, &$decided, &$editCalls) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                if (! $decided) {
                    $decided = true;
                    $this->approve($booking, $staff);   // decided while the first message is in flight
                }

                return $this->ok(123);
            }

            $editCalls++;
            if ($editCalls === 1) {
                // While the catch-up's edit is in flight, the booking completes and that refresh lands first.
                DB::table('bookings')->where('id', $booking->id)->update(['status' => 'completed', 'booking_version' => 3]);
                $this->assertTrue(app(TelegramBookingSender::class)->refresh($booking));
            }

            return $this->ok(123);
        });

        $this->handle($created);

        $edits = $this->edits();
        $this->assertCount(3, $edits);
        $this->assertStringContainsString('Status: Completed', (string) $edits[2]['text']);
        $this->assertSame(3, TelegramBookingMessage::query()->sole()->booking_version);
    }

    public function test_a_refresh_without_configuration_fails_permanently_without_a_call(): void
    {
        Http::fake([$this->endpoint('sendMessage') => $this->ok(123)]);
        $booking = $this->booking();
        app(TelegramBookingSender::class)->send($booking);
        $this->approve($booking, $this->staff());
        config(['services.telegram.bot_token' => '']);

        try {
            $this->handle($this->event('booking.approved', $booking));
            $this->fail('Expected a permanent outbox failure.');
        } catch (OutboxPermanentException $exception) {
            $this->assertSame('configuration', $exception->reason());
        }

        Http::assertSentCount(1);
    }

    public function test_a_refresh_that_cannot_format_fails_permanently(): void
    {
        Http::fake([$this->endpoint('sendMessage') => $this->ok(123)]);
        $booking = $this->booking();
        app(TelegramBookingSender::class)->send($booking);
        $this->approve($booking, $this->staff());
        config(['app.url' => 'https://'.str_repeat('a', 4000).'.example.invalid']);

        try {
            $this->handle($this->event('booking.approved', $booking));
            $this->fail('Expected a permanent outbox failure.');
        } catch (OutboxPermanentException $exception) {
            $this->assertSame('formatting', $exception->reason());
        }

        Http::assertSentCount(1);
        $this->assertSame(1, TelegramBookingMessage::query()->sole()->booking_version);
    }

    public function test_every_lifecycle_event_shares_the_telegram_key(): void
    {
        foreach ([
            'booking.created',
            'booking.approved',
            'booking.confirmed',
            'booking.cancelled',
            'booking.completed',
            'booking.no_show',
        ] as $type) {
            $this->assertSame(
                TelegramBookingNotificationHandler::class,
                config('outbox.events')[$type]['telegram'] ?? null,
            );
        }
    }

    private function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot123456789:'.str_repeat('A', 35).'/'.$method;
    }

    private function booking(): Booking
    {
        return Booking::factory()->create(['starts_at' => '2026-11-20 03:30:00'])->fresh();
    }

    private function staff(): User
    {
        return User::factory()->bookingStaff()->create();
    }

    private function approve(Booking $booking, User $staff): void
    {
        (new ApproveBooking)($staff, $booking->id, BookingStatus::Pending, new ActionContext('dashboard'));
    }

    private function event(string $type, Booking $booking): OutboxMessage
    {
        return OutboxMessage::query()->where('type', $type)->get()
            ->sole(fn (OutboxMessage $message): bool => $message->payload['booking_id'] === $booking->id);
    }

    private function created(Booking $booking): OutboxMessage
    {
        DB::transaction(fn () => app(BookingCreatedOutbox::class)->record($booking));

        return $this->event('booking.created', $booking);
    }

    private function handle(OutboxMessage $message): void
    {
        app(TelegramBookingNotificationHandler::class)->handle($message);
    }

    /** @return list<Request> */
    private function edits(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'))
            ->values()
            ->all();
    }

    private function ok(int $messageId = 123)
    {
        return Http::response(['ok' => true, 'result' => ['message_id' => $messageId]], 200);
    }
}
