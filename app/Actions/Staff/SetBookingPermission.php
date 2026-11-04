<?php

namespace App\Actions\Staff;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Rooms\RoomOwner;
use App\Support\Staff\StaffAccessLock;
use App\Support\Staff\StaffPermissionAudit;
use Illuminate\Validation\ValidationException;

final class SetBookingPermission
{
    public function __invoke(User $owner, int $staffId, bool $allowed): User
    {
        StaffPermissionAudit::requireSchema();

        return (new StaffAccessLock(requireSchema: true))->run(function () use ($owner, $staffId, $allowed): User {
            $actor = (new RoomOwner)->authorize($owner, lock: true);
            $target = User::query()->whereKey($staffId)->lockForUpdate()->first();
            if (! $target || $target->role !== UserRole::Admin || ($allowed && ! $target->hasVerifiedEmail())) {
                throw ValidationException::withMessages(['allowed' => __('admin.staff.invalid_target')]);
            }
            $before = $target->can_manage_bookings === true;
            if ($before !== $allowed) {
                $target->forceFill(['can_manage_bookings' => $allowed])->save();
                StaffPermissionAudit::record($target, $actor, $before, $allowed);
            }

            // Preserve the current locking read even if the caller has an older transaction snapshot.
            return $target;
        });
    }
}
