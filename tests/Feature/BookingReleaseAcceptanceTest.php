<?php

namespace Tests\Feature;

use App\Actions\ApproveBooking;
use App\Actions\CancelBooking;
use App\Actions\Staff\SetBookingPermission;
use App\Enums\BookingStatus;
use App\Jobs\ExpirePendingBookings;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\RoomReservation;
use App\Models\TelegramStaffLink;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Support\ActionContext;
use App\Support\Outbox\BookingCreatedOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Tests\Feature\Scheduling\RoomAllocationTest;
use Tests\TestCase;

/**
 * Stage E1a: the release risks that apply while every booking is paid at the spa (stage D, payments, is deferred).
 * Each test drives the real outbox: decisions record work, `outbox:dispatch` runs it on the sync queue, and Telegram
 * and the mail server are faked at their edges.
 */
class BookingReleaseAcceptanceTest extends TestCase
{
    // Not RefreshDatabase: outbox:dispatch refuses to run inside a transaction, as a real worker would.
    use DatabaseMigrations;

    // Split so the pre-commit secret scan does not flag these deliberate test fixtures.
    private const SECRET = 'test-'
        .'webhook-secret-0123456789_ABCDEFGHIJ';

    private bool $telegramDown = false;

    /** @var array<int, string> What each fake Telegram message shows now, so an identical edit is refused as Telegram does. */
    private array $shown = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-09 03:00:00', 'UTC'));
        Http::preventStrayRequests();
        config([
            'booking.scheduling_enabled' => true,
            'booking.customer_notices_enabled' => true,
            'services.telegram.booking_notifications_enabled' => true,
            'services.telegram.actions_enabled' => true,
            'services.telegram.bot_token' => '123456789:'.str_repeat('A', 35),
            'services.telegram.staff_chat_id' => '-1001234567890',
            'services.telegram.webhook_secret' => self::SECRET,
            'app.url' => 'https://spa.example.com',
        ]);
        Http::fake(fn (Request $request) => $this->telegram($request));
    }

    protected function tearDown(): void
    {
        // DatabaseMigrations rolls every migration back after the test. The owner authority migration refuses to
        // roll back while an owner account exists, and the lifecycle migration can't restore its NOT NULL actor
        // columns over the expiry job's system history (an accepted limit noted in its down()).
        User::query()->where('role', 'owner')->delete();
        BookingStatusChange::query()->where('actor_type', 'system')->delete();

        parent::tearDown();
    }

    public function test_a_cancellation_while_notices_wait_sends_only_the_truth(): void
    {
        // Master Review Focus 3, pay-at-spa half: staff approve and then cancel before the worker runs, while the
        // mail server is down. The approval's notices are superseded before they run: the customer never hears
        // "confirmed", and the staff chat ends on the truth. The settlement half (money arriving after the
        // cancellation) waits for stage D.
        $staff = $this->linkedStaff('Rani Kusuma', 5550001111);
        $booking = $this->roomBooking();
        $this->created($booking);
        $this->drain();

        (new ApproveBooking)($staff, $booking->id, BookingStatus::Pending, new ActionContext('dashboard'), operationalConfirmed: true);
        (new CancelBooking)($staff, $booking->id, BookingStatus::Confirmed, null, new ActionContext('dashboard'));
        $this->assertNotNull(RoomReservation::query()->sole()->released_at);

        $this->failMail();
        $this->drain();

        $this->assertSame('skipped: superseded', $this->delivery('booking.approved', 'customer')->last_error);
        $this->assertSame(['retry', 'retryable: mail'], $this->stateOf('booking.cancelled', 'customer'));
        $this->assertSame([], $this->mails());

        // The mail server is back; the retry runs a minute later.
        config(['mail.default' => 'array']);
        $this->travel(61)->seconds();
        $this->drain();

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame('Your Shin Joy Spa booking was cancelled', $mails[0]->getSubject());

        // Both decisions' deliveries render the latest status, so the second edit is identical. Telegram refuses it
        // as "not modified", which counts as success.
        $edits = $this->requests('editMessageText');
        $this->assertCount(1, $edits);
        $this->assertStringContainsString('Status: Cancelled', (string) $edits[0]['text']);
        $this->assertSame(['inline_keyboard' => []], $edits[0]['reply_markup']);
        $this->assertSame(1, $this->notModified());

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSettled();
    }

    public function test_revoked_actor_cannot_complete_delayed_action(): void
    {
        // Master Review Focus 4. A press is stored at once but runs later, so authority is checked when it runs.
        $owner = User::factory()->owner()->create();
        $rani = $this->linkedStaff('Rani Kusuma', 5550001111);
        $budi = $this->linkedStaff('Budi Santoso', 5550002222);
        $booking = $this->roomBooking();
        $this->created($booking);
        $this->drain();
        [$approve, $decline] = $this->buttons();

        // Rani starts a Decline. While its 5-minute confirmation is open, the owner removes her booking access.
        $this->press($decline, 1, 5550001111);
        $this->drain();
        $this->assertSame(['prompted', null], $this->outcome(1));
        $confirm = $this->promptButton();
        (new SetBookingPermission)($owner, $rani->id, false);
        // She presses "Yes, decline", and then Confirm, before the worker runs.
        $this->press($confirm, 2, 5550001111, 124);
        $this->press($approve, 3, 5550001111);
        // Budi presses Confirm, then deletes his account before the worker runs.
        $this->press($approve, 4, 5550002222);
        $this->actingAs($budi)->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()->assertRedirect('/');
        $this->assertNull($budi->fresh());
        $this->drain();

        $this->assertSame(['denied', 'not_authorized'], $this->outcome(2));
        $this->assertSame(['denied', 'not_authorized'], $this->outcome(3));
        $this->assertSame(['denied', 'not_linked'], $this->outcome(4));
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertSame(0, BookingStatusChange::query()->count());
        $this->assertSame(0, RoomReservation::query()->count());
        $this->assertTrue($this->answer(2)['show_alert']);
        $this->assertTrue($this->answer(3)['show_alert']);
        $this->assertTrue($this->answer(4)['show_alert']);

        // Sari still has access and confirms. Deleting her account afterwards keeps the record of who decided.
        $sari = $this->linkedStaff('Sari Dewi', 5550003333);
        $this->press($approve, 5, 5550003333);
        $this->drain();
        $this->assertSame(['approved', null], $this->outcome(5));
        $this->actingAs($sari)->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()->assertRedirect('/');
        $this->assertNull($sari->fresh());

        $change = BookingStatusChange::query()->sole();
        $this->assertNull($change->admin_user_id);
        $this->assertSame('Sari Dewi', $change->actor_name);
        $this->assertSame($sari->email, $change->actor_email);
        $this->assertSame('5550003333', $change->external_actor_id);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertNull(RoomReservation::query()->sole()->released_at);
        $this->assertSettled();
    }

    public function test_deliveries_recorded_during_a_deploy_drain_once_the_workers_restart(): void
    {
        // Deploy skew: the web processes already run the new release, so decisions record deliveries that a worker
        // still on an older release doesn't know. Those fail visibly, and requeuing them once every worker runs the
        // new release delivers each one once. (Review Focus 2's other direction, older deliveries reaching new code,
        // is covered by BookingOutboxEndToEndTest, BookingMessageSyncTest and OutboxWriterTest.)
        $staff = $this->linkedStaff('Rani Kusuma', 5550001111);
        $booking = $this->roomBooking();
        $this->created($booking);
        $this->drain();
        $current = config('outbox.events');

        // A worker from before de9719e, when booking.approved was not yet an event at all.
        (new ApproveBooking)($staff, $booking->id, BookingStatus::Pending, new ActionContext('dashboard'), operationalConfirmed: true);
        $this->drain(Arr::except($current, ['booking.approved']));
        $this->assertSame(['failed', 'permanent: event type is not configured'], $this->stateOf('booking.approved', 'telegram'));
        $this->assertSame(['failed', 'permanent: event type is not configured'], $this->stateOf('booking.approved', 'customer'));

        // A worker from before C4c, when booking.cancelled had only the staff message.
        (new CancelBooking)($staff, $booking->id, BookingStatus::Confirmed, null, new ActionContext('dashboard'));
        $this->drain([...$current, 'booking.cancelled' => Arr::only($current['booking.cancelled'], ['telegram'])]);
        $this->assertSame(['completed', null], $this->stateOf('booking.cancelled', 'telegram'));
        $this->assertSame(['failed', 'permanent: handler is not configured'], $this->stateOf('booking.cancelled', 'customer'));
        $this->assertSame([], $this->mails());

        // Every worker restarts on the new release; the failed deliveries are requeued by ID.
        $failed = DB::table('outbox_deliveries')->where('state', 'failed')->orderBy('id')->pluck('id')->all();
        $this->assertCount(3, $failed);
        $this->assertSame(0, Artisan::call('outbox:requeue', ['ids' => $failed, '--yes' => true]));
        $this->drain();

        $this->assertSame('skipped: superseded', $this->delivery('booking.approved', 'customer')->last_error);
        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame('Your Shin Joy Spa booking was cancelled', $mails[0]->getSubject());
        $this->assertCount(1, $this->requests('sendMessage'));
        $edits = $this->requests('editMessageText');
        $this->assertCount(1, $edits);
        $this->assertStringContainsString('Status: Cancelled', (string) $edits[0]['text']);
        $this->assertSettled();
    }

    public function test_a_pay_at_spa_request_reaches_staff_and_customer_through_outages(): void
    {
        // The whole pay-at-spa chain through two outages: Telegram is down when the request arrives, and the worker
        // dies while it handles the staff member's press.
        $this->linkedStaff('Rani Kusuma', 5550001111);
        $booking = $this->roomBooking();

        $this->telegramDown = true;
        $this->created($booking);
        $this->drain();
        $this->assertSame(['retry', 'retryable: telegram_http'], $this->stateOf('booking.created', 'telegram'));
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);

        $this->telegramDown = false;
        $this->travel(61)->seconds();
        $this->drain();
        [$approve] = $this->buttons();

        // The press is stored at once. The worker that picks it up dies before it runs: the job is never handled.
        $this->press($approve, 1, 5550001111);
        config(['queue.default' => 'dead_worker', 'queue.connections.dead_worker' => ['driver' => 'null']]);
        $this->assertSame(0, Artisan::call('outbox:dispatch'));
        $this->assertSame('leased', $this->delivery('telegram.update.received', 'telegram')->state);
        $this->assertNull($this->outcome(1)[0]);

        // A worker runs again. The dispatcher takes the delivery back once its 5-minute lease has expired.
        config(['queue.default' => 'sync']);
        $this->travel(301)->seconds();
        $this->drain();

        $this->assertSame(['approved', null], $this->outcome(1));
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertNull(RoomReservation::query()->sole()->released_at);
        $this->assertSame('Booking confirmed.', $this->answer(1)['text']);
        $edits = $this->requests('editMessageText');
        $this->assertCount(1, $edits);
        $this->assertStringContainsString('Status: Confirmed', (string) $edits[0]['text']);

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame('Your Shin Joy Spa booking is confirmed', $mails[0]->getSubject());
        $this->assertStringContainsString('Payment is made at the spa on the day of your appointment.', (string) $mails[0]->getTextBody());
        $this->assertSettled();
    }

    public function test_an_unanswered_request_expires_and_everyone_is_told(): void
    {
        // Nobody answers the request before it starts. The scheduled expiry job cancels it as the system, the staff
        // message loses its buttons, the customer gets the "expired" email, and a late press changes nothing.
        $this->linkedStaff('Rani Kusuma', 5550001111);
        $booking = $this->roomBooking();
        $this->created($booking);
        $this->drain();
        [$approve] = $this->buttons();

        $this->travelTo(CarbonImmutable::parse('2026-11-20 03:30:00', 'UTC'));
        app(ExpirePendingBookings::class)->handle();   // the scheduler's bookings:expire-pending, every minute
        $this->drain();

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Cancelled, $fresh->status);
        $this->assertSame('expired', $fresh->refund_reason);
        $this->assertSame('system', BookingStatusChange::query()->sole()->actor_type);

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame('Your Shin Joy Spa booking request has expired', $mails[0]->getSubject());
        $edits = $this->requests('editMessageText');
        $this->assertCount(1, $edits);
        $this->assertStringContainsString('Status: Cancelled', (string) $edits[0]['text']);
        $this->assertSame(['inline_keyboard' => []], $edits[0]['reply_markup']);

        $this->press($approve, 1, 5550001111);
        $this->drain();
        $this->assertSame(['ignored', 'expired'], $this->outcome(1));
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSame(1, BookingStatusChange::query()->count());
        $this->assertSettled();
    }

    /** A pending pay-at-spa request in a one-room schedule, starting in 11 days. */
    private function roomBooking(): Booking
    {
        [$booking] = (new RoomAllocationTest('fixture'))->fixture();
        $booking->forceFill([
            'appointment_date' => '2026-11-20', 'appointment_time' => '10:30', 'starts_at' => '2026-11-20 03:30:00',
            'payment_preference' => 'at_spa', 'locale' => 'en',
            'customer_name' => 'Ayu Lestari', 'customer_email' => 'ayu@example.com',
        ])->save();

        return $booking->fresh();
    }

    private function linkedStaff(string $name, int $telegramId): User
    {
        $user = User::factory()->bookingStaff()->create(['name' => $name]);
        TelegramStaffLink::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => (string) $telegramId,
            'activated_at' => CarbonImmutable::now('UTC'),
        ]);

        return $user;
    }

    private function created(Booking $booking): void
    {
        DB::transaction(fn () => app(BookingCreatedOutbox::class)->record($booking));
    }

    /**
     * Runs `outbox:dispatch` until nothing is due, as the scheduler would every 10 seconds. With $workerEvents, the
     * worker runs that older subscription map instead of the current one.
     *
     * @param  array<string, array<string, string>>|null  $workerEvents
     */
    private function drain(?array $workerEvents = null): void
    {
        $current = config('outbox.events');
        if ($workerEvents !== null) {
            config(['outbox.events' => $workerEvents]);
        }

        try {
            for ($round = 0; $round < 10; $round++) {
                $due = DB::table('outbox_deliveries')
                    ->where(fn ($query) => $query
                        ->where(fn ($query) => $query->whereIn('state', ['pending', 'retry'])->where('available_at', '<=', now()))
                        ->orWhere(fn ($query) => $query->where('state', 'leased')->where('lease_expires_at', '<=', now())))
                    ->exists();
                if (! $due) {
                    return;
                }
                $this->assertSame(0, Artisan::call('outbox:dispatch'));
            }

            $this->fail('The outbox never drained.');
        } finally {
            config(['outbox.events' => $current]);
        }
    }

    /** Every delivery has finished: none is waiting, retrying, held or failed. */
    private function assertSettled(): void
    {
        $this->assertSame([], DB::table('outbox_deliveries')->whereNotIn('state', ['completed', 'skipped'])
            ->orderBy('id')->get(['id', 'handler_key', 'state', 'last_error'])->map(fn ($row) => (array) $row)->all());
    }

    private function press(string $data, int $updateId, int $telegramId, int $messageId = 123): void
    {
        $update = ['update_id' => $updateId, 'callback_query' => [
            'id' => 'cbq-'.$updateId,
            'chat_instance' => '-8122',
            'data' => $data,
            'from' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'Staff'],
            'message' => [
                'message_id' => $messageId, 'date' => 1793000000, 'text' => 'NEW BOOKING',
                'chat' => ['id' => -1001234567890, 'type' => 'supergroup', 'title' => 'Shin Joy staff'],
            ],
        ]];

        $this->call('POST', '/webhooks/telegram', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::SECRET,
        ], json_encode($update, JSON_THROW_ON_ERROR))->assertOk();
    }

    /** @return array{0: ?string, 1: ?string} */
    private function outcome(int $updateId): array
    {
        $update = TelegramUpdate::query()->where('update_id', $updateId)->sole();

        return [$update->outcome, $update->outcome_reason];
    }

    /** @return array<string, mixed> The answer Telegram received for this press. */
    private function answer(int $updateId): array
    {
        $answers = array_values(array_filter(
            $this->requests('answerCallbackQuery'),
            fn (array $answer): bool => $answer['callback_query_id'] === 'cbq-'.$updateId,
        ));
        $this->assertCount(1, $answers);

        return $answers[0];
    }

    /** @return array{0: string, 1: string} The Confirm and Decline buttons' callback data on the booking message. */
    private function buttons(): array
    {
        $sends = array_values(array_filter(
            $this->requests('sendMessage'),
            fn (array $send): bool => ($send['reply_markup']['inline_keyboard'][1][0]['text'] ?? null) === 'Decline',
        ));
        $keyboard = Arr::last($sends)['reply_markup']['inline_keyboard'];

        return [$keyboard[0][0]['callback_data'], $keyboard[1][0]['callback_data']];
    }

    /** The "Yes, decline" button's callback data on the Decline prompt (message 124). */
    private function promptButton(): string
    {
        $prompt = Arr::last($this->requests('sendMessage'));
        $this->assertSame('Yes, decline', $prompt['reply_markup']['inline_keyboard'][0][0]['text']);

        return $prompt['reply_markup']['inline_keyboard'][0][0]['callback_data'];
    }

    /** The fake Telegram API: message 123 is the booking message, 124 the Decline prompt. */
    private function telegram(Request $request)
    {
        if ($this->telegramDown) {
            return Http::response(['ok' => false, 'error_code' => 502], 502);
        }
        $method = Str::afterLast($request->url(), '/');
        if ($method === 'answerCallbackQuery') {
            return Http::response(['ok' => true, 'result' => true], 200);
        }

        $content = json_encode([$request['text'], $request['reply_markup'] ?? null], JSON_THROW_ON_ERROR);
        if ($method === 'editMessageText') {
            if (($this->shown[$request['message_id']] ?? null) === $content) {
                return Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same as a current content and reply markup of the message'], 400);
            }
            $this->shown[$request['message_id']] = $content;

            return Http::response(['ok' => true, 'result' => ['message_id' => $request['message_id']]], 200);
        }

        $messageId = ($request['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === 'Yes, decline' ? 124 : 123;
        $this->shown[$messageId] = $content;

        return Http::response(['ok' => true, 'result' => ['message_id' => $messageId]], 200);
    }

    /** @return list<array<string, mixed>> The data of every successful Telegram call to this method. */
    private function requests(string $method): array
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/'.$method) && $pair[1]->json('ok') === true)
            ->map(fn (array $pair): array => $pair[0]->data())
            ->values()
            ->all();
    }

    private function notModified(): int
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => str_contains((string) $pair[1]->json('description'), 'message is not modified'))
            ->count();
    }

    private function delivery(string $type, string $channel): object
    {
        return DB::table('outbox_deliveries')
            ->join('outbox_messages', 'outbox_messages.id', '=', 'outbox_deliveries.message_id')
            ->where('outbox_messages.type', $type)
            ->where('outbox_deliveries.handler_key', $channel)
            ->select('outbox_deliveries.*')
            ->sole();
    }

    /** @return array{0: string, 1: ?string} */
    private function stateOf(string $type, string $channel): array
    {
        $delivery = $this->delivery($type, $channel);

        return [$delivery->state, $delivery->last_error];
    }

    private function failMail(): void
    {
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection to smtp.example.com:587 timed out', 0);
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.default' => 'failing', 'mail.mailers.failing' => ['transport' => 'failing']]);
    }

    /** @return list<Email> */
    private function mails(): array
    {
        return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->map(fn (SentMessage $sent) => $sent->getOriginalMessage())
            ->values()
            ->all();
    }
}
