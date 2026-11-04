<?php

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\RoomReservation;
use App\Models\User;
use App\Policies\BookingPolicy;
use App\Support\Scheduling\RoomAllocator;
use App\Support\Scheduling\ScheduleLock;
use App\Support\Staff\StaffAccessLock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        bool $operationalConfirmed = false,
    ): BookingStatusChange {
        return (new StaffAccessLock)->run(function () use (
            $admin,
            $booking,
            $currentStatus,
            $nextStatus,
            $reason,
            $ipAddress,
            $userAgent,
            $operationalConfirmed,
        ): BookingStatusChange {
            if (! $admin->exists || ! $admin->getKey() || $admin->getConnection() !== DB::connection()
                || ! $booking->exists || ! $booking->getKey() || $booking->getConnection() !== DB::connection()) {
                throw new AuthorizationException;
            }
            $actor = User::query()->whereKey($admin->getKey())->lockForUpdate()->first();
            if (! $actor || ! (new BookingPolicy)->updateStatus($actor, $booking)) {
                throw new AuthorizationException;
            }
            $roomAware = Schema::hasTable('room_reservations');
            if (config('booking.scheduling_enabled') && (! $roomAware || ! Schema::hasColumn('booking_status_changes', 'operational_confirmed_at'))) {
                throw ValidationException::withMessages(['room' => __('admin.status.room_unavailable')]);
            }
            $operation = fn (): BookingStatusChange => $this->transitionLocked($actor, $booking, $currentStatus, $nextStatus, $reason, $ipAddress, $userAgent, $operationalConfirmed, $roomAware);

            return $roomAware ? (new ScheduleLock)->run($operation) : $operation();
        });
    }

    private function transitionLocked(User $actor, Booking $booking, BookingStatus $currentStatus, BookingStatus $nextStatus, ?string $reason, ?string $ipAddress, ?string $userAgent, bool $operationalConfirmed, bool $roomAware): BookingStatusChange
    {
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

        $attestedAt = $this->applyRoomTransition($locked, $currentStatus, $nextStatus, $operationalConfirmed, $roomAware);
        $locked->status = $nextStatus;
        $locked->save();

        return BookingStatusChange::query()->forceCreate([
            'booking_id' => $locked->getKey(),
            'admin_user_id' => $actor->getKey(),
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            ...(Schema::hasColumn('booking_status_changes', 'operational_confirmed_at') ? ['operational_confirmed_at' => $attestedAt] : []),
            'from_status' => $currentStatus,
            'to_status' => $nextStatus,
            'reason' => $reason,
            'ip_address' => $ipAddress === null ? null : mb_substr($ipAddress, 0, 45),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 1024),
        ]);
    }

    private function applyRoomTransition(Booking $locked, BookingStatus $currentStatus, BookingStatus $nextStatus, bool $operationalConfirmed, bool $roomAware): ?CarbonImmutable
    {
        $attestedAt = null;
        if (config('booking.scheduling_enabled') && $currentStatus === BookingStatus::Pending && $nextStatus === BookingStatus::Confirmed) {
            if (! $operationalConfirmed) {
                throw ValidationException::withMessages(['operationalConfirmed' => __('admin.status.operational_required')]);
            }
            try {
                (new RoomAllocator)->allocateLocked($locked);
            } catch (ValidationException) {
                throw ValidationException::withMessages(['room' => __('admin.status.room_unavailable')]);
            }
            $attestedAt = CarbonImmutable::now('UTC')->setMicrosecond(0);
        }
        if ($roomAware && $nextStatus === BookingStatus::Cancelled) {
            $reservation = RoomReservation::query()->where('booking_id', $locked->getKey())->lockForUpdate()->first();
            $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
            if ($reservation && $reservation->released_at === null && $now->lessThan($reservation->starts_at)) {
                (new RoomAllocator)->releaseLocked($locked, 'booking_cancelled', $now);
            }
        }

        return $attestedAt;
    }
}
