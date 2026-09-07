<?php

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionBookingStatus
{
    public function __invoke(
        User $admin,
        Booking $booking,
        BookingStatus $currentStatus,
        BookingStatus $nextStatus,
        ?string $reason,
        ?string $ipAddress,
        ?string $userAgent,
    ): BookingStatusChange {
        return DB::transaction(function () use (
            $admin,
            $booking,
            $currentStatus,
            $nextStatus,
            $reason,
            $ipAddress,
            $userAgent,
        ): BookingStatusChange {
            $locked = Booking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== $currentStatus) {
                throw ValidationException::withMessages([
                    'currentStatus' => __('admin.status.stale'),
                ]);
            }

            if (! $currentStatus->canTransitionTo($nextStatus)) {
                throw ValidationException::withMessages([
                    'nextStatus' => __('admin.status.invalid'),
                ]);
            }

            $locked->status = $nextStatus;
            $locked->save();

            return BookingStatusChange::query()->forceCreate([
                'booking_id' => $locked->getKey(),
                'admin_user_id' => $admin->getKey(),
                'actor_name' => $admin->name,
                'actor_email' => $admin->email,
                'from_status' => $currentStatus,
                'to_status' => $nextStatus,
                'reason' => $reason,
                'ip_address' => $ipAddress === null ? null : mb_substr($ipAddress, 0, 45),
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 1024),
            ]);
        });
    }
}
