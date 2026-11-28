<?php

namespace App\Support\Scheduling;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Support\ActionContext;
use App\Support\Bookings\BookingStateWriter;
use App\Support\Rooms\RoomBlockInterval;
use App\Support\Rooms\RoomInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class AutoDeclineUnfillablePending
{
    /**
     * Cancel pending bookings overlapping the newly occupied window when every
     * compatible active room is now busy. Must run inside an active ScheduleLock.
     */
    public function declineLocked(
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        ?int $excludeBookingId = null,
    ): int {
        if (config('booking.scheduling_enabled') !== true) {
            return 0;
        }
        (new ScheduleLock)->assertHeld();
        if (! Schema::hasTable('bookings') || ! Schema::hasTable('rooms')
            || ! Schema::hasTable('room_service_variant') || ! Schema::hasTable('room_reservations')
            || ! Schema::hasTable('room_blocks')) {
            return 0;
        }

        $maxCleaning = (int) (DB::table('rooms')->where('is_active', true)->max('cleaning_minutes') ?? 0);
        $earliest = $windowStart->utc()->subMinutes($maxCleaning)->startOfSecond()->format('Y-m-d H:i:s');
        $latest = $windowEnd->utc()->addMinutes($maxCleaning)->endOfSecond()->format('Y-m-d H:i:s.u');

        $candidates = Booking::query()
            ->where('status', BookingStatus::Pending->value)
            ->when($excludeBookingId !== null, fn ($query) => $query->where('id', '!=', $excludeBookingId))
            ->whereNotNull('starts_at')
            ->whereNotNull('treatment_ends_at')
            ->whereNotNull('service_variant_id')
            ->where('starts_at', '<=', $latest)
            ->where('treatment_ends_at', '>=', $earliest)
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('room_reservations')
                    ->whereColumn('room_reservations.booking_id', 'bookings.id')
                    ->whereNull('room_reservations.released_at');
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
        $interval = new RoomBlockInterval;
        $writer = new BookingStateWriter;
        $declined = 0;

        foreach ($candidates as $candidate) {
            if (! $this->isUnfillableLocked($candidate, $interval)) {
                continue;
            }

            $candidate->refund_eligible_at = $now;
            $candidate->refund_reason = 'slot_full';
            $writer->transitionLocked(
                $candidate,
                BookingStatus::Cancelled,
                'slot_full',
                null,
                new ActionContext('system'),
            );
            $declined++;
        }

        return $declined;
    }

    private function isUnfillableLocked(Booking $candidate, RoomBlockInterval $interval): bool
    {
        if (! RoomInput::integer($candidate->guests, 1, 4)) {
            return false;
        }
        try {
            $start = $interval->instant($candidate, 'starts_at');
            $treatmentEnd = $interval->instant($candidate, 'treatment_ends_at');
        } catch (ValidationException) {
            return false;
        }
        if ($treatmentEnd <= $start) {
            return false;
        }

        $roomIds = DB::table('room_service_variant')
            ->where('service_variant_id', $candidate->service_variant_id)
            ->orderBy('room_id')
            ->lockForUpdate()
            ->pluck('room_id');
        if ($roomIds->isEmpty()) {
            return false;
        }

        $requestedType = $candidate->requested_room_type;
        $rooms = DB::table('rooms')
            ->whereIn('id', $roomIds)
            ->where('is_active', true)
            ->where('capacity', '>=', $candidate->guests)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'type', 'capacity', 'cleaning_minutes'])
            ->filter(fn (object $room): bool => $requestedType === null || $room->type === $requestedType)
            ->values();

        // Only decline when compatible rooms exist and every single one is occupied.
        if ($rooms->isEmpty()) {
            return false;
        }

        foreach ($rooms as $room) {
            if (! RoomInput::integer($room->cleaning_minutes, 0, 720)) {
                return false;
            }
            $end = $treatmentEnd->addMinutes((int) $room->cleaning_minutes);
            $occupied = false;
            foreach (['room_reservations', 'room_blocks'] as $table) {
                $rows = DB::table($table)
                    ->where('room_id', $room->id)
                    ->when($table === 'room_reservations', fn ($query) => $query->whereNull('released_at'))
                    ->where('starts_at', '<=', $end->endOfSecond()->format('Y-m-d H:i:s.u'))
                    ->where('ends_at', '>=', $start->startOfSecond()->format('Y-m-d H:i:s'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'starts_at', 'ends_at']);
                $terminal = $table === 'room_blocks' ? $interval->loadTerminations($rows->pluck('id')->all(), true) : [];
                if ($rows->contains(fn (object $row): bool => $interval->overlaps($row, $terminal[$row->id] ?? null, $start, $end))) {
                    $occupied = true;
                    break;
                }
            }
            if (! $occupied) {
                return false;
            }
        }

        return true;
    }
}
