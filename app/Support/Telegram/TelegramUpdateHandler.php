<?php

namespace App\Support\Telegram;

use App\Actions\Telegram\ClaimStaffEnrollment;
use App\Contracts\OutboxHandler;
use App\Exceptions\Outbox\OutboxPermanentException;
use App\Models\OutboxMessage;
use App\Models\TelegramEnrollment;
use App\Models\TelegramStaffLink;
use App\Models\TelegramUpdate;
use App\Queries\TelegramStaffQuery;
use App\Support\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TelegramUpdateHandler implements OutboxHandler
{
    public function __construct(
        private readonly OutboxWriter $outbox,
        private readonly ClaimStaffEnrollment $claim,
    ) {}

    public function handle(OutboxMessage $message): void
    {
        $updateId = $message->payload['telegram_update_id'] ?? null;
        if (! is_int($updateId) || $updateId <= 0) {
            throw new OutboxPermanentException('payload');
        }
        if (! TelegramUpdate::query()->whereKey($updateId)->exists()) {
            throw new OutboxPermanentException('update_missing');
        }

        // Three attempts, like StaffAccessLock's own outermost transaction: a deadlock or a busy
        // SQLite file retries here instead of waiting out the outbox backoff. The closure is
        // safe to rerun because the whole attempt rolls back.
        DB::transaction(function () use ($updateId): void {
            $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
            // Write first: this takes the row (and SQLite's write lock) before any read, and a replay
            // after a lost worker finds the outcome already set and changes nothing.
            $marked = DB::table('telegram_updates')->where('id', $updateId)->whereNull('outcome')
                ->update(['processed_at' => $now]);
            if ($marked !== 1) {
                return;
            }

            $update = TelegramUpdate::query()->findOrFail($updateId);
            [$outcome, $reason, $reply] = $this->decide($update);

            DB::table('telegram_updates')->where('id', $updateId)->update([
                'outcome' => $outcome,
                'outcome_reason' => $reason,
                'start_token' => null,
            ]);

            if ($reply !== null) {
                $this->outbox->record('telegram.reply', "telegram.reply:{$updateId}", [
                    'chat_id' => $update->payload['chat_id'],
                    'text' => $reply,
                ]);
            }
        }, 3);
    }

    /** @return array{0: string, 1: ?string, 2: ?string} the outcome, its reason and the reply text */
    private function decide(TelegramUpdate $update): array
    {
        $payload = $update->payload;
        if ($update->kind !== 'start' || ! is_array($payload)) {
            return ['ignored', 'unsupported', null];   // C3 adds callback_query handling here
        }
        if ($payload['chat_type'] !== 'private') {
            return ['ignored', 'not_private', null];
        }
        if ($payload['from_is_bot'] === true) {
            return ['ignored', 'bot_sender', null];
        }
        if ($update->start_token === null) {
            return ['ignored', 'no_token', __('admin.telegram.bot_start_help')];
        }

        $telegramUserId = (string) $payload['from_id'];
        try {
            ($this->claim)($update->start_token, $telegramUserId, $update->received_at);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $key = array_key_first($errors);
            $repeat = $key === 'token' ? $this->repeatForClaimer($update->start_token, $telegramUserId) : null;
            if ($repeat !== null) {
                return ['ignored', ...$repeat];
            }

            return ['rejected', $key === 'token' ? 'invalid_token' : 'telegram_refused', $errors[$key][0]];
        }

        return ['claimed', null, __('admin.telegram.bot_claimed', ['digits' => TelegramStaffQuery::hint($telegramUserId)])];
    }

    /**
     * Opening the link a second time must not contradict the first answer. Only the Telegram
     * account that claimed this token hears that it was claimed, so nothing leaks to anyone else.
     *
     * @return array{0: string, 1: string}|null the reason and the reply text
     */
    private function repeatForClaimer(string $token, string $telegramUserId): ?array
    {
        $enrollment = TelegramEnrollment::query()->where('token_hash', hash('sha256', $token))->first();
        if ($enrollment === null || $enrollment->claimed_telegram_user_id !== $telegramUserId || $enrollment->cancelled_at !== null) {
            return null;
        }
        if ($enrollment->activated_at === null) {
            return ['already_claimed', __('admin.telegram.bot_claimed', ['digits' => TelegramStaffQuery::hint($telegramUserId)])];
        }
        $linked = TelegramStaffLink::query()->where('user_id', $enrollment->user_id)
            ->where('telegram_user_id', $telegramUserId)->exists();

        return $linked ? ['already_linked', __('admin.telegram.bot_already_linked')] : null;
    }
}
