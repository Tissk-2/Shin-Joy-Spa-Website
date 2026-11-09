<?php

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Policies\BookingPolicy;
use App\Support\ActionContext;
use App\Support\Bookings\BookingMutation;
use App\Support\Bookings\BookingSchedule;
use App\Support\Bookings\BookingStateWriter;
use App\Support\Staff\StaffAccessLock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RecordNoShow
{
    public function __invoke(
        User $actor,
        int $bookingId,
        BookingStatus $expected,
        ActionContext $context,
        ?string $reason = null,
    ): Booking {
        return (new StaffAccessLock)->run(function () use ($actor, $bookingId, $expected, $context, $reason): Booking {
            if (! $actor->exists || ! $actor->getKey() || $actor->getConnection() !== DB::connection()) {
                throw new AuthorizationException;
            }
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
            // The policy deliberately ignores the booking argument; the locked
            // booking is reloaded later under the schedule guard.
            if (! $lockedActor || ! (new BookingPolicy)->noShow($lockedActor, (new Booking)->forceFill(['id' => $bookingId]))) {
                throw new AuthorizationException;
            }
            $roomAware = Schema::hasTable('room_reservations');
            if (config('booking.scheduling_enabled') && (! $roomAware || ! Schema::hasColumn('booking_status_changes', 'operational_confirmed_at'))) {
                throw ValidationException::withMessages(['room' => __('admin.status.room_unavailable')]);
            }

            return (new BookingMutation)->run($bookingId, function (Booking $locked) use ($lockedActor, $expected, $context, $reason): Booking {
                if ($locked->status !== $expected) {
                    throw ValidationException::withMessages(['currentStatus' => __('admin.status.stale')]);
                }

                $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
                if ($now->lessThan((new BookingSchedule)->treatmentEndsAt($locked))) {
                    throw ValidationException::withMessages(['nextStatus' => __('admin.status.no_show_too_early')]);
                }

                $locked->refund_eligible_at = $now;
                $locked->refund_reason = 'no_show';

                return (new BookingStateWriter)->transitionLocked(
                    $locked,
                    BookingStatus::NoShow,
                    $reason,
                    $lockedActor,
                    $context,
                );
            });
        });
    }
}
