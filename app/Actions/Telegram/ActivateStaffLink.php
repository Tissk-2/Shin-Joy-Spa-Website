<?php

namespace App\Actions\Telegram;

use App\Models\TelegramEnrollment;
use App\Models\TelegramStaffLink;
use App\Models\User;
use App\Policies\BookingPolicy;
use App\Support\Rooms\RoomOwner;
use App\Support\Staff\StaffAccessLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ActivateStaffLink
{
    public function __invoke(User $owner, int $enrollmentId): TelegramStaffLink
    {
        return (new StaffAccessLock(requireSchema: true))->run(function () use ($owner, $enrollmentId): TelegramStaffLink {
            $actor = (new RoomOwner)->authorize($owner, lock: true);
            $enrollment = TelegramEnrollment::query()->whereKey($enrollmentId)->lockForUpdate()->first();
            if (! $enrollment || $enrollment->claimed_at === null || $enrollment->activated_at !== null || $enrollment->cancelled_at !== null) {
                throw ValidationException::withMessages(['enrollment' => __('admin.telegram.not_claimed')]);
            }
            $target = User::query()->whereKey($enrollment->user_id)->lockForUpdate()->first();
            if (! $target || ! (new BookingPolicy)->create($target)) {
                throw ValidationException::withMessages(['staff' => __('admin.telegram.invalid_target')]);
            }
            // Locking reads: a caller with an older MySQL snapshot must not miss a newer link.
            if (TelegramStaffLink::query()->where('user_id', $target->id)->lockForUpdate()->value('id') !== null) {
                throw ValidationException::withMessages(['staff' => __('admin.telegram.already_linked')]);
            }
            if (TelegramStaffLink::query()->where('telegram_user_id', $enrollment->claimed_telegram_user_id)->lockForUpdate()->value('id') !== null) {
                throw ValidationException::withMessages(['telegram' => __('admin.telegram.telegram_in_use')]);
            }
            $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
            $link = TelegramStaffLink::query()->create([
                'user_id' => $target->id,
                'telegram_user_id' => $enrollment->claimed_telegram_user_id,
                'enrollment_id' => $enrollment->id,
                'activated_at' => $now,
            ]);
            $enrollment->forceFill([
                'activated_at' => $now,
                'activated_by_user_id' => $actor->id,
            ])->save();
            DB::table('telegram_staff_link_changes')->insert([
                'target_user_id' => $target->id,
                'target_name' => $target->name,
                'telegram_user_id' => $enrollment->claimed_telegram_user_id,
                'action' => 'activated',
                'actor_user_id' => $actor->id,
                'actor_name' => $actor->name,
                'created_at' => $now,
            ]);

            return $link;
        });
    }
}
