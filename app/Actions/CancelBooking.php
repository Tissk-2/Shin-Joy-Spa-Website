<?php

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\RoomReservation;
use App\Models\User;
use App\Policies\BookingPolicy;
use App\Support\ActionContext;
use App\Support\Bookings\BookingMutation;
use App\Support\Bookings\BookingStateWriter;
use App\Support\Scheduling\RoomAllocator;
use App\Support\Staff\StaffAccessLock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CancelBooking
{
    public function __invoke(
        User $actor,
        int $bookingId,
        BookingStatus $expected,
        ?string $reason,
        ActionContext $context,
    ): Booking {
        return (new StaffAccessLock)->run(function () use ($actor, $bookingId, $expected, $reason, $context): Booking {
            if (! $actor->exists || ! $actor->getKey() || $actor->getConnection() !== DB::connection()) {
                throw new AuthorizationException;
            }
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
            // The policy deliberately ignores the booking argument; the locked
            // booking is reloaded later under the schedule guard.
            if (! $lockedActor || ! (new BookingPolicy)->cancel($lockedActor, (new Booking)->forceFill(['id' => $bookingId]))) {
                throw new AuthorizationException;
            }
            $roomAware = Schema::hasTable('room_reservations');
            if (config('booking.scheduling_enabled') && (! $roomAware || ! Schema::hasColumn('booking_status_changes', 'operational_confirmed_at'))) {
                throw ValidationException::withMessages(['room' => __('admin.status.room_unavailable')]);
            }

            return (new BookingMutation)->run($bookingId, function (Booking $locked) use ($lockedActor, $expected, $reason, $context, $roomAware): Booking {
                if ($locked->status !== $expected) {
                    throw ValidationException::withMessages(['currentStatus' => __('admin.status.stale')]);
                }

                $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
                $locked->refund_eligible_at = $now;
                $locked->refund_reason = 'cancelled_by_staff';

                if ($roomAware && $locked->payment_preference !== 'online') {
                    $reservation = RoomReservation::query()->where('booking_id', $locked->getKey())->lockForUpdate()->first();
                    if ($reservation && $reservation->released_at === null && $now->lessThan($reservation->starts_at)) {
                        (new RoomAllocator)->releaseLocked($locked, 'booking_cancelled', $now);
                    }
                }

                return (new BookingStateWriter)->transitionLocked(
                    $locked,
                    BookingStatus::Cancelled,
                    $reason,
                    $lockedActor,
                    $context,
                );
            });
        });
    }
}
