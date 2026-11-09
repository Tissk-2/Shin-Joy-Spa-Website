<?php

namespace App\Actions\Telegram;

use App\Models\TelegramEnrollment;
use App\Models\TelegramStaffLink;
use App\Models\User;
use App\Support\Rooms\RoomOwner;
use App\Support\Staff\StaffAccessLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RevokeStaffLink
{
    /** Returns true when an active link was removed, false when only open enrollments were cancelled. */
    public function __invoke(User $owner, int $staffId): bool
    {
        return (new StaffAccessLock(requireSchema: true))->run(function () use ($owner, $staffId): bool {
            $actor = (new RoomOwner)->authorize($owner, lock: true);
            $link = TelegramStaffLink::query()->where('user_id', $staffId)->lockForUpdate()->first();
            $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
            $cancelled = TelegramEnrollment::query()
                ->where('user_id', $staffId)
                ->whereNull('activated_at')
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => $now]);
            if ($link === null && $cancelled === 0) {
                throw ValidationException::withMessages(['staff' => __('admin.telegram.not_linked')]);
            }
            if ($link !== null) {
                $target = User::query()->whereKey($link->user_id)->first();
                DB::table('telegram_staff_link_changes')->insert([
                    'target_user_id' => $link->user_id,
                    'target_name' => $target->name,
                    'telegram_user_id' => $link->telegram_user_id,
                    'action' => 'revoked',
                    'actor_user_id' => $actor->id,
                    'actor_name' => $actor->name,
                    'created_at' => $now,
                ]);
                $link->delete();
            }

            return $link !== null;
        });
    }
}
