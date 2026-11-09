<?php

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\User;
use App\Support\ActionContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard compatibility dispatcher. Every transition is delegated to one
 * explicit lifecycle action so there is exactly one state-write path.
 */
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
        bool $operationalConfirmed = false,
    ): BookingStatusChange {
        if (! $booking->exists || ! $booking->getKey() || $booking->getConnection() !== DB::connection()) {
            throw new AuthorizationException;
        }

        $context = new ActionContext('dashboard', $ipAddress, $userAgent);

        match (true) {
            $currentStatus === BookingStatus::Pending && $nextStatus === BookingStatus::Confirmed => (new ApproveBooking)($admin, $booking->getKey(), $currentStatus, $context, $operationalConfirmed, $reason),
            ($currentStatus === BookingStatus::Pending || $currentStatus === BookingStatus::Confirmed) && $nextStatus === BookingStatus::Cancelled => (new CancelBooking)($admin, $booking->getKey(), $currentStatus, $reason, $context),
            $currentStatus === BookingStatus::Confirmed && $nextStatus === BookingStatus::Completed => (new CompleteBooking)($admin, $booking->getKey(), $currentStatus, $context, $reason),
            $currentStatus === BookingStatus::Confirmed && $nextStatus === BookingStatus::NoShow => (new RecordNoShow)($admin, $booking->getKey(), $currentStatus, $context, $reason),
            default => throw ValidationException::withMessages(['nextStatus' => __('admin.status.invalid')]),
        };

        return BookingStatusChange::query()
            ->where('booking_id', $booking->getKey())
            ->latest('id')
            ->firstOrFail();
    }
}
