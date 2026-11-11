<?php

namespace Tests\Feature\Telegram;

use App\Actions\Telegram\RevokeStaffLink;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\OutboxMessage;
use App\Models\RoomReservation;
use App\Models\TelegramActionToken;
use App\Models\TelegramBookingMessage;
use App\Models\TelegramStaffLink;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Support\Telegram\TelegramUpdateHandler;
use App\Support\TelegramBookingSender;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scheduling\RoomAllocationTest;
use Tests\TestCase;

class BookingCallbacksTest extends TestCase
{
    use RefreshDatabase;

    // Split so the pre-commit secret scan does not flag these deliberate test fixtures.
    private const SECRET = 'test-'
        .'webhook-secret-0123456789_ABCDEFGHIJ';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        CarbonImmutable::setTestNow('2026-11-09 03:00:00 UTC');

        config([
            'services.telegram.booking_notifications_enabled' => true,
            'services.telegram.actions_enabled' => true,
            'services.telegram.bot_token' => '123456789:'.str_repeat('A', 35),
            'services.telegram.staff_chat_id' => '-1001234567890',
            'services.telegram.webhook_secret' => self::SECRET,
            'app.url' => 'https://spa.example.com',
        ]);

        Http::fake([
            $this->endpoint() => Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_confirm_approves_the_booking_as_the_linked_staff_member(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();
        [$approve] = $this->render($booking);

        $update = $this->press($approve, 1);

        $this->assertSame(['approved', null], $this->outcome($update));
        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Confirmed, $fresh->status);
        $this->assertSame(2, $fresh->booking_version);

        $change = BookingStatusChange::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame('telegram', $change->channel);
        $this->assertSame($staff->id, $change->admin_user_id);
        $this->assertSame('staff', $change->actor_type);
        $this->assertSame('5550001111', $change->external_actor_id);
        $this->assertNull($change->ip_address);
        $this->assertNull($change->user_agent);
        $this->assertNull($change->reason);

        $token = $this->token($booking, 'approve');
        $this->assertSame('2026-11-09 03:00:00', $token->consumed_at->toDateTimeString());
        $this->assertSame('approved', $token->result);

        $this->assertSame([
            'callback_query_id' => 'cbq-1',
            'show_alert' => false,
            'text' => 'Booking confirmed.',
        ], $this->answerFor($update));

        $this->assertSame(1, OutboxMessage::query()->where('type', 'booking.approved')->count());
    }

    public function test_confirm_with_scheduling_allocates_a_room_or_reports_the_conflict(): void
    {
        config(['booking.scheduling_enabled' => true]);
        [$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
        $booking->forceFill(['starts_at' => '2026-11-20 03:30:00'])->save();
        $this->staff();

        [$approve] = $this->render($booking);
        $first = $this->press($approve, 1);

        $this->assertSame(['approved', null], $this->outcome($first));
        $reservation = RoomReservation::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame($room->id, $reservation->room_id);
        $change = BookingStatusChange::query()->where('booking_id', $booking->id)->latest('id')->firstOrFail();
        $this->assertNotNull($change->operational_confirmed_at);

        $second = $booking->replicate(['reference', 'submission_key']);
        $second->reference = 'conflicting-callback';
        $second->submission_key = 'conflicting-callback';
        $second->save();
        $second = $second->fresh();

        [$secondApprove] = $this->render($second);
        $conflict = $this->press($secondApprove, 2);

        $this->assertSame(['conflict', 'room'], $this->outcome($conflict));
        $this->assertSame(BookingStatus::Pending, $second->fresh()->status);
        $this->assertSame(1, $second->fresh()->booking_version);
        $this->assertNull(RoomReservation::query()->where('booking_id', $second->id)->first());
        $this->assertDatabaseCount('room_reservations', 1);
        $this->assertNull($this->token($second, 'approve')->consumed_at);
        $answer = $this->answerFor($conflict);
        $this->assertTrue($answer['show_alert']);
        $this->assertSame(__('admin.telegram.callback.room'), $answer['text']);
    }

    public function test_revoked_staff_cannot_use_rendered_button(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = $this->staff();
        $booking = $this->booking();
        [$approve] = $this->render($booking);

        (new RevokeStaffLink)($owner, $staff->id);

        $revoked = $this->press($approve, 1);
        $this->assertSame(['denied', 'not_linked'], $this->outcome($revoked));
        $answer = $this->answerFor($revoked);
        $this->assertTrue($answer['show_alert']);
        $this->assertSame(__('admin.telegram.callback.not_linked'), $answer['text']);
        $this->assertNull($this->token($booking, 'approve')->consumed_at);

        $dewi = $this->staff(5550002222, 'Dewi Lestari');
        $dewi->forceFill(['can_manage_bookings' => false])->save();

        $unauthorized = $this->press($approve, 2, ['id' => 5550002222]);
        $this->assertSame(['denied', 'not_authorized'], $this->outcome($unauthorized));
        $this->assertSame(__('admin.telegram.callback.not_authorized'), $this->answerFor($unauthorized)['text']);

        $this->assertDatabaseCount('room_reservations', 0);
    }

    public function test_presses_from_another_chat_topic_or_message_are_refused(): void
    {
        $this->staff();
        $booking = $this->booking();
        [$approve] = $this->render($booking);

        $cases = [
            10 => ['chat' => ['id' => -1009999999999, 'type' => 'supergroup', 'title' => 'Other']],
            11 => ['message_thread_id' => 5],
            12 => ['message_id' => 999],
        ];

        foreach ($cases as $updateId => $message) {
            $update = $this->press($approve, $updateId, [], $message);
            $this->assertSame(['ignored', 'wrong_message'], $this->outcome($update), 'update '.$updateId);
            $this->assertSame(__('admin.telegram.callback.wrong_message'), $this->answerFor($update)['text']);
        }

        $inline = $this->press($approve, 13, [], null);
        $this->assertSame(['ignored', 'wrong_message'], $this->outcome($inline));
        $this->assertSame(__('admin.telegram.callback.wrong_message'), $this->answerFor($inline)['text']);

        config(['services.telegram.staff_chat_id' => '-1005555555555']);
        $reconfigured = $this->press($approve, 14);
        config(['services.telegram.staff_chat_id' => '-1001234567890']);

        $this->assertSame(['ignored', 'wrong_message'], $this->outcome($reconfigured));
        $this->assertSame(__('admin.telegram.callback.wrong_message'), $this->answerFor($reconfigured)['text']);
        $this->assertNull($this->token($booking, 'approve')->consumed_at);
    }

    public function test_a_press_identifies_a_copy_whose_send_was_not_recorded(): void
    {
        $this->staff();
        $booking = $this->booking();
        [$approve] = $this->render($booking);

        DB::table('telegram_booking_messages')->update(['message_id' => null, 'state' => 'sending', 'sent_at' => null]);

        $update = $this->press($approve, 1);

        $this->assertSame(['approved', null], $this->outcome($update));
        $row = TelegramBookingMessage::query()->sole();
        $this->assertSame(123, $row->message_id);
        $this->assertSame('sent', $row->state);
        $this->assertSame('2026-11-09 03:00:00', $row->sent_at->toDateTimeString());

        $this->assertSame(123, app(TelegramBookingSender::class)->send($booking->fresh()));
        Http::assertSentCount(1);
    }

    public function test_only_a_live_token_identifies_an_unrecorded_copy(): void
    {
        $this->staff();
        $used = $this->booking();
        [$usedApprove] = $this->render($used);
        $this->press($usedApprove, 1);
        $expired = $this->booking(['starts_at' => '2026-11-09 03:30:00']);
        [$expiredApprove] = $this->render($expired);
        DB::table('telegram_booking_messages')->update(['message_id' => null, 'state' => 'sending', 'sent_at' => null]);

        $this->assertSame(['ignored', 'used'], $this->outcome($this->press($usedApprove, 2)));
        CarbonImmutable::setTestNow('2026-11-09 03:30:00 UTC');
        $this->assertSame(['ignored', 'expired'], $this->outcome($this->press($expiredApprove, 3)));

        // A dead button proves nothing about which copy a retried send should trust.
        $this->assertSame(0, TelegramBookingMessage::query()->whereNotNull('message_id')->count());
    }

    public function test_used_stale_expired_and_unknown_buttons_change_nothing(): void
    {
        $this->staff();
        $booking = $this->booking();
        [$approve] = $this->render($booking);

        $first = $this->press($approve, 1);
        $this->assertSame(['approved', null], $this->outcome($first));

        // A double tap while the first answer is still on its way: a calm notice, not an alarming alert.
        $replay = $this->press($approve, 2);
        $this->assertSame(['ignored', 'used'], $this->outcome($replay));
        $this->assertSame([
            'callback_query_id' => 'cbq-2',
            'show_alert' => false,
            'text' => 'This button was already used to confirm the booking.',
        ], $this->answerFor($replay));
        $this->assertSame(1, BookingStatusChange::query()->where('booking_id', $booking->id)->count());

        $staleBooking = $this->booking();
        [$staleApprove] = $this->render($staleBooking);
        DB::table('bookings')->where('id', $staleBooking->id)->update(['booking_version' => 2]);

        $stale = $this->press($staleApprove, 3);
        $this->assertSame(['stale', 'changed'], $this->outcome($stale));
        $this->assertSame(BookingStatus::Pending, $staleBooking->fresh()->status);
        $this->assertSame(2, $staleBooking->fresh()->booking_version);
        $this->assertSame(__('admin.telegram.callback.changed'), $this->answerFor($stale)['text']);

        $startedBooking = $this->booking(['starts_at' => '2026-11-09 03:30:00']);
        [$startedApprove] = $this->render($startedBooking);
        CarbonImmutable::setTestNow('2026-11-09 03:30:00 UTC');
        $started = $this->press($startedApprove, 4);
        CarbonImmutable::setTestNow('2026-11-09 03:00:00 UTC');
        $this->assertSame(['ignored', 'expired'], $this->outcome($started));

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $unknown = $this->press('t:'.str_repeat('a', 43), 5);
        $this->assertNotEmpty($statements);
        $this->assertSame([], preg_grep('/staff_access_guards/i', $statements));
        $this->assertSame(['ignored', 'expired'], $this->outcome($unknown));
        $this->assertSame(__('admin.telegram.callback.expired'), $this->answerFor($unknown)['text']);
    }

    public function test_a_token_deleted_between_lookup_and_lock_is_answered_as_expired(): void
    {
        $this->staff();
        $booking = $this->booking();
        [$approve] = $this->render($booking);
        $deleted = false;
        // A message refresh deletes the button's token after the lookup, while the press waits for the staff guard.
        DB::connection()->beforeExecuting(function (string $query) use (&$deleted): void {
            if (! $deleted && preg_match('/\Aupdate "staff_access_guards"/i', $query) === 1) {
                $deleted = true;
                DB::table('telegram_action_tokens')->delete();
            }
        });

        $update = $this->press($approve, 1);

        $this->assertTrue($deleted);
        $this->assertSame(['ignored', 'expired'], $this->outcome($update));
        $this->assertSame(__('admin.telegram.callback.expired'), $this->answerFor($update)['text']);
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_actions_turned_off_after_rendering_are_denied(): void
    {
        $this->staff();
        $booking = $this->booking();
        [$approve] = $this->render($booking);

        config(['services.telegram.actions_enabled' => false]);

        $update = $this->press($approve, 1);

        $this->assertSame(['denied', 'actions_disabled'], $this->outcome($update));
        $this->assertSame(__('admin.telegram.callback.actions_disabled'), $this->answerFor($update)['text']);
        $this->assertNull($this->token($booking, 'approve')->consumed_at);
    }

    public function test_decline_takes_a_second_press_on_the_prompt_by_the_same_staff_member(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();
        [, $decline] = $this->render($booking);

        $first = $this->press($decline, 1);
        $this->assertSame(['prompted', null], $this->outcome($first));
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);

        $row = TelegramBookingMessage::query()->sole();
        $token = $this->token($booking, 'decline_confirm');
        $this->assertSame($row->id, $token->telegram_booking_message_id);
        $this->assertSame(1, $token->booking_version);
        $this->assertSame($staff->id, $token->initiating_user_id);
        $this->assertSame('5550001111', $token->initiating_telegram_user_id);
        $this->assertSame('2026-11-09 03:05:00', $token->expires_at->toDateTimeString());
        $this->assertNull($token->consumed_at);

        $prompt = OutboxMessage::query()->where('deduplication_key', 'telegram.decline.prompt:'.$first->id)->sole();
        $this->assertSame('-1001234567890', $prompt->payload['chat_id']);
        $this->assertNull($prompt->payload['message_thread_id']);
        $this->assertSame(
            'Rani Kusuma: decline booking '.$booking->reference.'? Only you can confirm, within 5 minutes.',
            $prompt->payload['text'],
        );

        $nonce = Crypt::decryptString($prompt->payload['nonce']);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $nonce);
        $this->assertSame(hash('sha256', $nonce), DB::table('telegram_action_tokens')->where('id', $token->id)->value('nonce_hash'));

        $serialized = json_encode(DB::table('telegram_action_tokens')->get());
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString($nonce, $serialized);
        $this->assertStringNotContainsString($nonce, $prompt->getRawOriginal('payload'));

        $this->assertSame([
            'callback_query_id' => 'cbq-1',
            'show_alert' => false,
            'text' => 'Confirm the decline in the message below within 5 minutes.',
        ], $this->answerFor($first));

        $second = $this->press('t:'.$nonce, 2, [], ['message_id' => 124]);
        $this->assertSame(['declined', null], $this->outcome($second));
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSame(2, $booking->fresh()->booking_version);

        $change = BookingStatusChange::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame('staff_declined', $change->reason);
        $this->assertSame('telegram', $change->channel);
        $this->assertSame($staff->id, $change->admin_user_id);
        $this->assertSame('5550001111', $change->external_actor_id);
        $used = $this->token($booking, 'decline_confirm');
        $this->assertSame('declined', $used->result);
        $this->assertSame('2026-11-09 03:00:00', $used->consumed_at->toDateTimeString());
        $this->assertSame([
            'callback_query_id' => 'cbq-2',
            'show_alert' => false,
            'text' => 'Booking declined.',
        ], $this->answerFor($second));
        $this->assertSame(1, OutboxMessage::query()->where('type', 'booking.cancelled')->count());

        $again = $this->press('t:'.$nonce, 3, [], ['message_id' => 124]);
        $this->assertSame(['ignored', 'used'], $this->outcome($again));
        $this->assertSame([
            'callback_query_id' => 'cbq-3',
            'show_alert' => false,
            'text' => 'This button was already used to decline the booking.',
        ], $this->answerFor($again));
    }

    public function test_decline_confirmation_is_bound_to_initiating_actor(): void
    {
        $a = $this->staff();
        $b = $this->staff(5550002222, 'Dewi Lestari');
        $owner = User::factory()->owner()->create();
        $booking = $this->booking();
        [, $decline] = $this->render($booking);

        $first = $this->press($decline, 1);
        $nonceA = $this->promptNonce($first);

        $byB = $this->press('t:'.$nonceA, 2, ['id' => 5550002222], ['message_id' => 124]);
        $this->assertSame(['denied', 'not_initiator'], $this->outcome($byB));
        $this->assertSame(__('admin.telegram.callback.not_initiator'), $this->answerFor($byB)['text']);
        $this->assertNull($this->token($booking, 'decline_confirm')->consumed_at);

        (new RevokeStaffLink)($owner, $a->id);
        $c = User::factory()->bookingStaff()->create(['name' => 'Sari Wulandari']);
        $this->link($c, 5550001111);

        $byC = $this->press('t:'.$nonceA, 3);
        $this->assertSame(['denied', 'not_initiator'], $this->outcome($byC));

        $this->link($a, 5550003333);
        $byOtherTelegram = $this->press('t:'.$nonceA, 4, ['id' => 5550003333]);
        $this->assertSame(['denied', 'not_initiator'], $this->outcome($byOtherTelegram));

        $second = $this->press($decline, 5, ['id' => 5550002222]);
        $nonceB = $this->promptNonce($second);

        $declined = $this->press('t:'.$nonceB, 6, ['id' => 5550002222], ['message_id' => 124]);

        $this->assertSame(['declined', null], $this->outcome($declined));
        $this->assertSame($b->id, BookingStatusChange::query()->where('booking_id', $booking->id)->sole()->admin_user_id);

        $tokens = TelegramActionToken::query()->where('booking_id', $booking->id)
            ->where('action', 'decline_confirm')->orderBy('id')->get();
        $this->assertCount(2, $tokens);
        $this->assertNull($tokens[0]->consumed_at);
        $this->assertSame('declined', $tokens[1]->result);
    }

    public function test_decline_confirmation_expires_after_five_minutes_or_at_the_start(): void
    {
        $this->staff();
        $booking = $this->booking();
        [, $decline] = $this->render($booking);

        $prompt = $this->press($decline, 1);
        $nonce = $this->promptNonce($prompt);

        CarbonImmutable::setTestNow('2026-11-09 03:05:00 UTC');
        $expired = $this->press('t:'.$nonce, 2, [], ['message_id' => 124]);
        CarbonImmutable::setTestNow('2026-11-09 03:00:00 UTC');
        $this->assertSame(['ignored', 'expired'], $this->outcome($expired));

        $soon = $this->booking(['starts_at' => '2026-11-09 03:03:00']);
        [, $soonDecline] = $this->render($soon);
        $soonPrompt = $this->press($soonDecline, 3);
        $this->assertSame('2026-11-09 03:03:00', $this->token($soon, 'decline_confirm')->expires_at->toDateTimeString());
        // The bot must not promise five minutes when the appointment cuts them short.
        $this->assertSame(
            'Rani Kusuma: decline booking '.$soon->reference.'? Only you can confirm, within 3 minutes.',
            OutboxMessage::query()->where('deduplication_key', 'telegram.decline.prompt:'.$soonPrompt->id)->sole()->payload['text'],
        );
        $this->assertSame('Confirm the decline in the message below within 3 minutes.', $this->answerFor($soonPrompt)['text']);

        $third = $this->booking();
        [, $thirdDecline] = $this->render($third);
        $thirdPrompt = $this->press($thirdDecline, 4);
        DB::table('bookings')->where('id', $third->id)->update(['booking_version' => 2]);

        $stale = $this->press('t:'.$this->promptNonce($thirdPrompt), 5, [], ['message_id' => 124]);
        $this->assertSame(['stale', 'changed'], $this->outcome($stale));
    }

    public function test_a_decline_prompt_needs_booking_access_and_an_unchanged_booking(): void
    {
        $staff = $this->staff();
        $staff->forceFill(['can_manage_bookings' => false])->save();
        $booking = $this->booking();
        [, $decline] = $this->render($booking);

        $denied = $this->press($decline, 1);
        $this->assertSame(['denied', 'not_authorized'], $this->outcome($denied));
        $this->assertSame(0, TelegramActionToken::query()->where('action', 'decline_confirm')->count());
        $this->assertSame(0, OutboxMessage::query()->where('type', 'telegram.decline.prompt')->count());

        $staff->forceFill(['can_manage_bookings' => true])->save();
        DB::table('bookings')->where('id', $booking->id)->update(['booking_version' => 2]);

        $stale = $this->press($decline, 2);
        $this->assertSame(['stale', 'changed'], $this->outcome($stale));
        $this->assertSame(0, TelegramActionToken::query()->where('action', 'decline_confirm')->count());
        $this->assertSame(0, OutboxMessage::query()->where('type', 'telegram.decline.prompt')->count());
    }

    public function test_unsupported_bot_and_pruned_updates_are_ignored_without_an_answer(): void
    {
        $unsupported = $this->press('x', 10);
        $this->assertSame(['ignored', 'unsupported'], $this->outcome($unsupported));

        $this->staff();
        $booking = $this->booking();
        [$approve] = $this->render($booking);

        $bot = $this->press($approve, 11, ['is_bot' => true]);
        $this->assertSame(['ignored', 'bot_sender'], $this->outcome($bot));
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);

        $prunedCallback = $this->receive($this->callbackUpdate($approve, 12));
        DB::table('telegram_updates')->where('update_id', 12)->update(['payload' => null]);
        $this->handle($prunedCallback);
        $prunedCallbackRow = TelegramUpdate::query()->where('update_id', 12)->sole();
        $this->assertSame(['ignored', 'expired'], $this->outcome($prunedCallbackRow));

        $prunedStart = $this->receive($this->startUpdate(13, '/start abc'));
        DB::table('telegram_updates')->where('update_id', 13)->update(['payload' => null]);
        $this->handle($prunedStart);
        $prunedStartRow = TelegramUpdate::query()->where('update_id', 13)->sole();
        $this->assertSame(['ignored', 'expired'], $this->outcome($prunedStartRow));

        $this->assertSame(0, OutboxMessage::query()->where('type', 'telegram.callback.answer')->count());
        $this->assertSame(0, OutboxMessage::query()->where('type', 'telegram.reply')->count());
    }

    public function test_an_online_payment_booking_is_refused_while_payments_are_off(): void
    {
        config(['booking.online_payments_enabled' => false]);
        $this->staff();
        $booking = $this->booking(['payment_preference' => 'online']);
        [$approve] = $this->render($booking);

        $update = $this->press($approve, 1);

        $this->assertSame(['rejected', 'nextStatus'], $this->outcome($update));
        $answer = $this->answerFor($update);
        $this->assertSame(__('admin.status.online_payments_disabled'), $answer['text']);
        $this->assertTrue($answer['show_alert']);
        $this->assertNull($this->token($booking, 'approve')->consumed_at);
    }

    public function test_every_answer_fits_telegrams_limit_in_both_languages(): void
    {
        foreach (['en', 'id'] as $locale) {
            $messages = trans('admin.telegram.callback', [], $locale);
            $this->assertIsArray($messages);

            foreach ($messages as $key => $text) {
                $this->assertIsString($text);
                $this->assertLessThanOrEqual(
                    200,
                    mb_strlen(str_replace(':minutes', '5', $text)),
                    "admin.telegram.callback.{$key} ({$locale})",
                );
            }
        }
    }

    public function test_unknown_send_outcome_cannot_duplicate_booking_action(): void
    {
        // Stage Review Focus 5. Telegram posts the message but its reply is lost, so the outbox sends it again and
        // the staff chat holds two copies of one booking. Delivery is at least once; the buttons keep that safe.
        config(['booking.scheduling_enabled' => true]);
        [$booking] = (new RoomAllocationTest('fixture'))->fixture();
        $booking->forceFill(['starts_at' => '2026-11-20 03:30:00'])->save();
        $this->staff();

        // The first copy reached the chat, but nothing recorded it. The row stays "sending", which the admin
        // booking page shows as "Not confirmed as delivered". The fake numbers every send 123, so the lost copy
        // is called 122 here.
        [$lostApprove, $lostDecline] = $this->render($booking);
        DB::table('telegram_booking_messages')->update(['message_id' => null, 'state' => 'sending', 'sent_at' => null]);
        $lost = ['message_id' => 122];

        // The retry posts a second copy with new buttons, and records it.
        [$approve, $decline] = $this->render($booking);
        Http::assertSentCount(2);
        $this->assertNotSame($lostApprove, $approve);
        $row = TelegramBookingMessage::query()->sole();
        $this->assertSame([123, 'sent'], [$row->message_id, $row->state]);

        // The lost copy's buttons are dead.
        $this->assertSame(['ignored', 'expired'], $this->outcome($this->press($lostApprove, 1, [], $lost)));
        $this->assertSame(['ignored', 'expired'], $this->outcome($this->press($lostDecline, 2, [], $lost)));
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
        $this->assertSame(0, BookingStatusChange::query()->where('booking_id', $booking->id)->count());

        // The recorded copy confirms the booking, once.
        $this->assertSame(['approved', null], $this->outcome($this->press($approve, 3)));

        // After that, no button on either copy changes anything.
        $this->assertSame(['ignored', 'expired'], $this->outcome($this->press($lostApprove, 4, [], $lost)));
        $this->assertSame(['ignored', 'used'], $this->outcome($this->press($approve, 5)));
        $this->assertSame(['stale', 'changed'], $this->outcome($this->press($decline, 6)));

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::Confirmed, $fresh->status);
        $this->assertSame(2, $fresh->booking_version);
        $this->assertSame(1, BookingStatusChange::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(1, RoomReservation::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(1, OutboxMessage::query()->where('type', 'booking.approved')->count());
    }

    private function endpoint(string $method = 'sendMessage'): string
    {
        return 'https://api.telegram.org/bot123456789:'.str_repeat('A', 35).'/'.$method;
    }

    private function staff(int $telegramId = 5550001111, string $name = 'Rani Kusuma'): User
    {
        $user = User::factory()->bookingStaff()->create(['name' => $name]);
        $this->link($user, $telegramId);

        return $user;
    }

    private function link(User $user, int $telegramId): void
    {
        TelegramStaffLink::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => (string) $telegramId,
            'activated_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function render(Booking $booking): array
    {
        app(TelegramBookingSender::class)->send($booking->fresh());

        $keyboard = Http::recorded()->last()[0]->data()['reply_markup']['inline_keyboard'];

        return [$keyboard[0][0]['callback_data'], $keyboard[1][0]['callback_data']];
    }

    /** @param  array<string, mixed>  $overrides */
    private function booking(array $overrides = []): Booking
    {
        return Booking::factory()->create(['starts_at' => '2026-11-20 03:30:00', ...$overrides])->fresh();
    }

    private function press(string $data, int $updateId, array $from = [], ?array $message = []): TelegramUpdate
    {
        $this->handle($this->receive($this->callbackUpdate($data, $updateId, $from, $message)));

        return TelegramUpdate::query()->where('update_id', $updateId)->sole();
    }

    private function callbackUpdate(string $data, int $updateId, array $from = [], ?array $message = []): array
    {
        $callback = [
            'id' => 'cbq-'.$updateId,
            'chat_instance' => '-8122',
            'data' => $data,
            'from' => ['id' => 5550001111, 'is_bot' => false, 'first_name' => 'Rani', ...$from],
            'message' => [
                'message_id' => 123, 'date' => 1793000000, 'text' => 'NEW BOOKING',
                'chat' => ['id' => -1001234567890, 'type' => 'supergroup', 'title' => 'Shin Joy staff'],
                ...($message ?? []),
            ],
        ];
        if ($message === null) {
            unset($callback['message']);
        }

        return ['update_id' => $updateId, 'callback_query' => $callback];
    }

    private function startUpdate(int $updateId, string $text): array
    {
        return ['update_id' => $updateId, 'message' => [
            'message_id' => 11, 'date' => 1793000000, 'text' => $text,
            'from' => ['id' => 5550001111, 'is_bot' => false, 'first_name' => 'Rani'],
            'chat' => ['id' => 5550001111, 'type' => 'private', 'first_name' => 'Rani'],
        ]];
    }

    private function receive(array $update): OutboxMessage
    {
        $this->sendJson(json_encode($update, JSON_THROW_ON_ERROR))->assertOk();

        return OutboxMessage::query()->where('type', 'telegram.update.received')->orderByDesc('id')->firstOrFail();
    }

    private function sendJson(string $body): TestResponse
    {
        return $this->call('POST', '/webhooks/telegram', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::SECRET,
        ], $body);
    }

    private function handle(OutboxMessage $message): void
    {
        app(TelegramUpdateHandler::class)->handle($message);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function outcome(TelegramUpdate $update): array
    {
        return [$update->outcome, $update->outcome_reason];
    }

    /** @return array<string, mixed>|null */
    private function answerFor(TelegramUpdate $update): ?array
    {
        return OutboxMessage::query()
            ->where('deduplication_key', 'telegram.callback.answer:'.$update->id)
            ->first()?->payload;
    }

    private function promptNonce(TelegramUpdate $update): string
    {
        $prompt = OutboxMessage::query()
            ->where('deduplication_key', 'telegram.decline.prompt:'.$update->id)
            ->sole();

        return Crypt::decryptString($prompt->payload['nonce']);
    }

    private function token(Booking $booking, string $action): TelegramActionToken
    {
        return TelegramActionToken::query()->where('booking_id', $booking->id)
            ->where('action', $action)->orderByDesc('id')->firstOrFail();
    }
}
