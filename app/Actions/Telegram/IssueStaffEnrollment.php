<?php

namespace App\Actions\Telegram;

use App\Models\TelegramEnrollment;
use App\Models\TelegramStaffLink;
use App\Models\User;
use App\Policies\BookingPolicy;
use App\Support\Rooms\RoomOwner;
use App\Support\Staff\StaffAccessLock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class IssueStaffEnrollment
{
    public function __invoke(User $owner, int $staffId): string
    {
        return (new StaffAccessLock(requireSchema: true))->run(function () use ($owner, $staffId): string {
            $actor = (new RoomOwner)->authorize($owner, lock: true);
            $target = User::query()->whereKey($staffId)->lockForUpdate()->first();
            if (! $target || ! (new BookingPolicy)->create($target)) {
                throw ValidationException::withMessages(['staff' => __('admin.telegram.invalid_target')]);
            }
            // Locking read: a caller with an older MySQL snapshot must not miss a newer link.
            if (TelegramStaffLink::query()->where('user_id', $target->id)->lockForUpdate()->value('id') !== null) {
                throw ValidationException::withMessages(['staff' => __('admin.telegram.already_linked')]);
            }
            $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
            TelegramEnrollment::query()
                ->where('user_id', $target->id)
                ->whereNull('activated_at')
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => $now]);
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            TelegramEnrollment::query()->create([
                'user_id' => $target->id,
                'issued_by_user_id' => $actor->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $now->addMinutes(15),
            ]);

            return $token;
        });
    }
}
