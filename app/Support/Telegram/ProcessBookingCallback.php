<?php

namespace App\Support\Telegram;

use App\Actions\ApproveBooking;
use App\Actions\CancelBooking;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\TelegramActionToken;
use App\Models\TelegramBookingMessage;
use App\Models\TelegramStaffLink;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Policies\BookingPolicy;
use App\Support\ActionContext;
use App\Support\Outbox\OutboxWriter;
use App\Support\Staff\StaffAccessLock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

final class ProcessBookingCallback
{
    public const DECLINE_CONFIRM_LABEL = 'Yes, decline';

    public const DECLINE_MINUTES = 5;

    public function __construct(private readonly OutboxWriter $outbox) {}

    /**
     * Runs inside TelegramUpdateHandler's transaction, after its conditional write on the update row, so the
     * lock order is: update row, message row, staff guard, booking. The handler marks pruned rows (null
     * payload) itself, without an answer.
     *
     * @return array{0: string, 1: ?string} the update's outcome and reason
     */
    public function __invoke(TelegramUpdate $update): array
    {
        $p = $update->payload;
        if (! is_string($p['data']) || preg_match('/\At:([A-Za-z0-9_-]{43})\z/', $p['data'], $m) !== 1) {
            return ['ignored', 'unsupported'];   // not one of this bot's buttons: nothing to answer
        }
        if ($p['from_is_bot'] === true) {   // mutation A
            return ['ignored', 'bot_sender'];
        }
        if (! BookingActionKeyboard::actionsEnabled()) {   // mutation F
            return $this->answer($update, 'denied', 'actions_disabled');
        }

        // An unknown nonce is answered before the staff guard that dashboard approvals wait on.
        $token = TelegramActionToken::query()->where('nonce_hash', hash('sha256', $m[1]))->first();
        if ($token === null) {
            return $this->answer($update, 'ignored', 'expired');
        }
        $row = TelegramBookingMessage::query()->find($token->telegram_booking_message_id);
        if (! $this->fromItsMessage($row, $p, $token->action)) {
            return $this->answer($update, 'ignored', 'wrong_message');
        }
        $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
        if ($token->action !== 'decline_confirm' && $row->message_id === null   // mutation N
            && $token->consumed_at === null && $token->expires_at > $now) {   // mutations N2, N3
            // The send's response was lost, or its final save failed. Only the latest copy carries live tokens,
            // so this press identifies it, and a retried send now finds it and posts no second copy.
            TelegramBookingMessage::query()->whereKey($row->id)->whereNull('message_id')->update([
                'message_id' => $p['message_id'],
                'state' => 'sent',
                'sent_at' => $now,
            ]);
        }

        return (new StaffAccessLock(requireSchema: true))->run(
            fn (): array => $this->decide($update, $token->id, (string) $p['from_id']),
        );
    }

    /** @return array{0: string, 1: ?string} */
    private function decide(TelegramUpdate $update, int $tokenId, string $telegramUserId): array
    {
        $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
        // Re-read under the guard, so a second press of the same button sees the first one's result.
        $token = TelegramActionToken::query()->whereKey($tokenId)->lockForUpdate()->firstOrFail();
        if ($token->consumed_at !== null) {   // mutation E2
            // Usually a second tap while the first answer is still on its way: say calmly what the button did.
            return $this->answer($update, 'ignored', 'used', __('admin.telegram.callback.used_'.$token->result));
        }
        if ($token->expires_at <= $now) {   // mutation E1
            return $this->answer($update, 'ignored', 'expired');
        }

        $link = TelegramStaffLink::query()->where('telegram_user_id', $telegramUserId)->lockForUpdate()->first();
        $user = $link === null ? null : User::query()->whereKey($link->user_id)->first();
        if ($user === null) {
            return $this->answer($update, 'denied', 'not_linked');
        }
        // The confirmation belongs to whoever pressed Decline, as both their staff account and their Telegram account.
        if ($token->action === 'decline_confirm'
            && ((int) $token->initiating_user_id !== $user->id   // mutation D1
                || $token->initiating_telegram_user_id !== $telegramUserId)) {   // mutation D2
            return $this->answer($update, 'denied', 'not_initiator');
        }

        $context = new ActionContext('telegram', null, null, $telegramUserId);
        try {
            return match ($token->action) {
                'approve' => $this->approve($update, $token, $user, $context, $now),
                'decline_prompt' => $this->prompt($update, $token, $user, $telegramUserId, $now),
                'decline_confirm' => $this->decline($update, $token, $user, $context, $now),
            };
        } catch (AuthorizationException) {
            return $this->answer($update, 'denied', 'not_authorized');
        } catch (ValidationException $exception) {
            $key = (string) array_key_first($exception->errors());

            return match ($key) {
                'room' => $this->answer($update, 'conflict', 'room'),
                'currentStatus' => $this->answer($update, 'stale', 'changed'),
                default => $this->answer($update, 'rejected', $key, $exception->errors()[$key][0]),
            };
        }
    }

    /** Confirm is the staff member's attestation that therapists were checked for every guest; its label says so. */
    private function approve(TelegramUpdate $update, TelegramActionToken $token, User $user, ActionContext $context, CarbonImmutable $now): array
    {
        (new ApproveBooking)($user, $token->booking_id, BookingStatus::Pending, $context,
            operationalConfirmed: true, expectedVersion: $token->booking_version);
        $token->forceFill(['consumed_at' => $now, 'result' => 'approved'])->save();   // mutation T

        return $this->answer($update, 'approved', null);
    }

    private function prompt(TelegramUpdate $update, TelegramActionToken $token, User $user, string $telegramUserId, CarbonImmutable $now): array
    {
        if (! (new BookingPolicy)->cancel($user, (new Booking)->forceFill(['id' => $token->booking_id]))) {   // mutation P2
            return $this->answer($update, 'denied', 'not_authorized');
        }
        // Every status change bumps the version, so the version alone tells whether the booking moved on. A locking
        // read, because on MySQL a plain one would see the snapshot from before this transaction waited for the guard.
        $booking = Booking::query()->lockForUpdate()->findOrFail($token->booking_id);
        if ($booking->booking_version !== $token->booking_version) {   // mutation P1
            return $this->answer($update, 'stale', 'changed');
        }

        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = $now->addMinutes(self::DECLINE_MINUTES);
        $expires = $booking->starts_at !== null && $booking->starts_at < $expires ? $booking->starts_at : $expires;   // mutation X
        // Say the real window: an appointment that starts sooner cuts the five minutes short.
        $minutes = max(1, intdiv($expires->getTimestamp() - $now->getTimestamp(), 60));   // mutation Y
        TelegramActionToken::query()->create([
            'telegram_booking_message_id' => $token->telegram_booking_message_id,
            'booking_id' => $booking->id,
            'booking_version' => $booking->booking_version,
            'action' => 'decline_confirm',
            'nonce_hash' => hash('sha256', $nonce),
            'initiating_user_id' => $user->id,
            'initiating_telegram_user_id' => $telegramUserId,
            'expires_at' => $expires,
        ]);
        $row = TelegramBookingMessage::query()->findOrFail($token->telegram_booking_message_id);
        // The prompt's button must survive delivery retries, so its nonce is kept encrypted, never plain.
        $this->outbox->record('telegram.decline.prompt', "telegram.decline.prompt:{$update->id}", [
            'chat_id' => $row->chat_id,
            'message_thread_id' => $row->message_thread_id,
            'text' => __('admin.telegram.decline_prompt', [
                'name' => $user->name,
                'reference' => $booking->reference,
                'minutes' => $minutes,
            ]),
            'nonce' => Crypt::encryptString($nonce),
        ]);

        return $this->answer($update, 'prompted', null, __('admin.telegram.callback.prompted', ['minutes' => $minutes]));
    }

    private function decline(TelegramUpdate $update, TelegramActionToken $token, User $user, ActionContext $context, CarbonImmutable $now): array
    {
        (new CancelBooking)($user, $token->booking_id, BookingStatus::Pending, 'staff_declined', $context, $token->booking_version);
        $token->forceFill(['consumed_at' => $now, 'result' => 'declined'])->save();

        return $this->answer($update, 'declined', null);
    }

    /**
     * The press must come from the configured staff chat, in the message's topic, on the copy that carries the
     * token. A Decline prompt is a message of its own, so only the booking message's buttons are pinned to its
     * message ID. A row without a message ID is matched by its live token; see the caller.
     *
     * @param  array<string, mixed>  $p
     */
    private function fromItsMessage(?TelegramBookingMessage $row, array $p, string $action): bool
    {
        return $row !== null
            && $p['chat_id'] !== null
            && (string) $p['chat_id'] === $row->chat_id   // mutation M1
            && $row->chat_id === config('services.telegram.staff_chat_id')   // mutation M2
            && $p['message_thread_id'] === $row->message_thread_id   // mutation M3
            && ($action === 'decline_confirm' || $row->message_id === null || $row->message_id === $p['message_id']);   // mutations M5, M4
    }

    /**
     * Records the answer that stops the button's spinner, and returns the update's outcome. Refusals show an
     * alert the staff member must close; successes show a brief notice.
     *
     * @return array{0: string, 1: ?string}
     */
    private function answer(TelegramUpdate $update, string $outcome, ?string $reason, ?string $text = null): array
    {
        $this->outbox->record('telegram.callback.answer', "telegram.callback.answer:{$update->id}", [
            'callback_query_id' => $update->payload['callback_query_id'],
            'text' => $text ?? __('admin.telegram.callback.'.($reason ?? $outcome)),
            'show_alert' => ! in_array($reason ?? $outcome, ['approved', 'declined', 'prompted', 'used'], true),   // mutation S
        ]);

        return [$outcome, $reason];
    }
}
