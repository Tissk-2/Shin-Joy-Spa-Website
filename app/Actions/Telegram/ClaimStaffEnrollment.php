<?php

namespace App\Actions\Telegram;

use App\Models\TelegramEnrollment;
use App\Models\TelegramStaffLink;
use App\Support\Staff\StaffAccessLock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ClaimStaffEnrollment
{
    /**
     * $receivedAt is when our webhook stored the /start (server time), so a tap made before expiry
     * still counts when the queue processes it later. Without it, expiry is checked against now.
     */
    public function __invoke(string $token, string $telegramUserId, ?CarbonImmutable $receivedAt = null): TelegramEnrollment
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
            throw ValidationException::withMessages(['token' => __('admin.telegram.invalid_token')]);
        }
        if (preg_match('/\A[1-9][0-9]{0,19}\z/', $telegramUserId) !== 1) {
            throw ValidationException::withMessages(['telegram' => __('admin.telegram.invalid_identity')]);
        }

        // Anyone can send the bot /start. Refuse unknown tokens before taking the staff guard that
        // dashboard approvals also wait on; a 256-bit token makes this lookup reveal nothing.
        if (! TelegramEnrollment::query()->where('token_hash', hash('sha256', $token))->exists()) {
            throw ValidationException::withMessages(['token' => __('admin.telegram.invalid_token')]);
        }

        return (new StaffAccessLock(requireSchema: true))->run(function () use ($token, $telegramUserId, $receivedAt): TelegramEnrollment {
            $enrollment = TelegramEnrollment::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
            if (! $enrollment || $enrollment->cancelled_at !== null || $enrollment->claimed_at !== null || $enrollment->expires_at <= ($receivedAt ?? $now)) {
                throw ValidationException::withMessages(['token' => __('admin.telegram.invalid_token')]);
            }
            // Locking read: a caller with an older MySQL snapshot must not miss a newer link.
            if (TelegramStaffLink::query()->where('telegram_user_id', $telegramUserId)->lockForUpdate()->value('id') !== null) {
                throw ValidationException::withMessages(['telegram' => __('admin.telegram.telegram_in_use')]);
            }
            $enrollment->forceFill([
                'claimed_telegram_user_id' => $telegramUserId,
                'claimed_at' => $now,
            ])->save();

            return $enrollment;
        });
    }
}
