<?php

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
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

class ApproveBooking
{
    public function __invoke(
        User $actor,
        int $bookingId,
        BookingStatus $expected,
        ActionContext $context,
        bool $operationalConfirmed = false,
        ?string $reason = null,
        ?int $expectedVersion = null,
    ): Booking {
        return (new StaffAccessLock)->run(function () use ($actor, $bookingId, $expected, $context, $operationalConfirmed, $reason, $expectedVersion): Booking {
            if (! $actor->exists || ! $actor->getKey() || $actor->getConnection() !== DB::connection()) {
                throw new AuthorizationException;
            }
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
            // The policy deliberately ignores the booking argument; the locked
            // booking is reloaded later under the schedule guard.
            if (! $lockedActor || ! (new BookingPolicy)->approve($lockedActor, (new Booking)->forceFill(['id' => $bookingId]))) {
                throw new AuthorizationException;
            }
            $roomAware = Schema::hasTable('room_reservations');
            if (config('booking.scheduling_enabled') && (! $roomAware || ! Schema::hasColumn('booking_status_changes', 'operational_confirmed_at'))) {
                throw ValidationException::withMessages(['room' => __('admin.status.room_unavailable')]);
            }

            return (new BookingMutation)->run($bookingId, function (Booking $locked) use ($lockedActor, $expected, $context, $operationalConfirmed, $reason, $expectedVersion): Booking {
                // Telegram buttons carry the version they were rendered for; a newer version is stale even in the same status.
                if ($locked->status !== $expected || ($expectedVersion !== null && $locked->booking_version !== $expectedVersion)) {
                    throw ValidationException::withMessages(['currentStatus' => __('admin.status.stale')]);
                }

                $online = $locked->payment_preference === 'online';
                if ($online && ! config('booking.online_payments_enabled')) {
                    throw ValidationException::withMessages(['nextStatus' => __('admin.status.online_payments_disabled')]);
                }

                $attestedAt = null;
                if (config('booking.scheduling_enabled')) {
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

                $locked->approved_at = CarbonImmutable::now('UTC')->setMicrosecond(0);

                return (new BookingStateWriter)->transitionLocked(
                    $locked,
                    $online ? BookingStatus::AwaitingPayment : BookingStatus::Confirmed,
                    $reason,
                    $lockedActor,
                    $context,
                    $attestedAt,
                );
            });
        });
    }
}
