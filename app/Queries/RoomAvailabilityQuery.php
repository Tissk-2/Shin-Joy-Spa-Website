<?php

namespace App\Queries;

use App\Models\BookingSettings;
use App\Models\Room;
use App\Models\ServiceVariant;
use App\Support\Rooms\RoomBlockInterval;
use App\Support\Rooms\RoomBlockTerminationSchema;
use App\Support\Rooms\RoomInput;
use App\Support\Scheduling\BookingAvailabilityChoice;
use App\Support\Scheduling\BookingAvailabilityInput;
use App\Support\Scheduling\BookingAvailabilitySchema;
use App\Support\Scheduling\BookingAvailabilityUnavailable;
use App\Support\Scheduling\BookingSettingsInput;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RoomAvailabilityQuery
{
    /**
     * Read-only observation: approval must recheck rooms; therapist allocation is separate.
     *
     * @param  bool  $staffEntry  staff record walk-ins and calls as they happen, so
     *                            the owner's minimum notice for customers doesn't apply
     */
    public function forSelection(array $validatedSelection, bool $staffEntry = false): array
    {
        $selection = (new BookingAvailabilityInput)->validate($validatedSelection);
        if (config('booking.scheduling_enabled') !== true) {
            throw new BookingAvailabilityUnavailable;
        }
        $now = CarbonImmutable::now('UTC');
        (new BookingAvailabilitySchema)->requireReadable();
        $settings = $this->settings();
        $today = $now->setTimezone('Asia/Jakarta')->startOfDay();
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $selection['date'], 'Asia/Jakarta');
        if ($date < $today || $date > $today->addDays($settings['booking_horizon_days'])) {
            throw ValidationException::withMessages(['date' => __('validation.availability.horizon')]);
        }
        ['variant' => $variant, 'duration' => $duration] = (new BookingAvailabilityChoice)->resolve($selection);
        $rooms = $this->rooms($variant, $selection['guests'], $selection['room_type'])
            ->filter(fn (Room $room) => $selection['room_type'] === null || $room->getRawOriginal('type') === $selection['room_type'])->values();
        $this->validateRooms($rooms);
        $first = $date->setTime(9, 0)->utc();
        $last = $date->setTime(20, 0)->utc();
        $maxCleaning = (int) $rooms->max('cleaning_minutes');
        try {
            // Coarse inclusive whole-day superset, even when the lead cutoff removes all starts.
            $occupancy = $this->occupancy($rooms, $first, $last->addMinutes($duration + $maxCleaning));
            $this->validateOccupancy($occupancy);
        } catch (ValidationException $error) {
            throw new BookingAvailabilityUnavailable(previous: $error);
        }
        $pending = $this->pendingRequests($rooms, $first->subMinutes($maxCleaning), $last->addMinutes($duration + $maxCleaning));
        $cutoff = $staffEntry ? $now : $now->addMinutes($settings['minimum_lead_minutes']);
        $closing = $date->setTime(21, 0)->utc();
        $slots = [];
        for ($start = $first; $start <= $last; $start = $start->addMinutes(30)) {
            if ($start > $now && $start >= $cutoff && $start->addMinutes($duration) <= $closing) {
                $projected = $this->project($rooms, $start, $duration, $occupancy);
                $freeRooms = [];
                foreach ($rooms as $index => $room) {
                    if ($projected[$index]['available'] === true) {
                        $freeRooms[] = $room;
                    }
                }
                $waitingCount = $this->countWaitingRequests($freeRooms, $start, $duration, $pending);
                $slot = [
                    'start_at' => $start->toISOString(),
                    'rooms' => $projected,
                    'has_waiting_requests' => $freeRooms !== [] && $waitingCount >= count($freeRooms),
                ];
                if ($staffEntry) {
                    $slot['waiting_requests_count'] = $waitingCount;
                }
                $slots[] = $slot;
            }
        }

        return ['generated_at' => $now->toISOString(), 'slots' => $slots];
    }

    private function settings(): array
    {
        $settings = BookingSettings::query()->find(1);
        if (! $settings) {
            throw new BookingAvailabilityUnavailable;
        }
        try {
            return (new BookingSettingsInput)->persisted($settings);
        } catch (ValidationException $error) {
            throw new BookingAvailabilityUnavailable(previous: $error);
        }
    }

    private function validateRooms(Collection $rooms): void
    {
        foreach ($rooms as $room) {
            if (! RoomInput::integer($room->getRawOriginal('cleaning_minutes'), 0, 720)
                || ! RoomInput::integer($room->getRawOriginal('capacity'), 1, 4294967295)) {
                throw new BookingAvailabilityUnavailable;
            }
        }
    }

    /**
     * Internal read-only helper: variant and duration must come from trusted catalog
     * resolution, never directly from a public request. This does not reserve a room
     * or promise availability at a later write. All intervals are half-open and UTC.
     * Subsecond inputs are rejected to match second-precision stored timestamps.
     *
     * @return list<array{code: string, name: string, type: ?string, capacity: int, available: bool}>
     */
    public function forInterval(ServiceVariant $variant, int $guests, CarbonImmutable $startsAt, int $durationMinutes, ?string $roomType = null): array
    {
        (new RoomBlockTerminationSchema)->requireReadable();
        // The existing quote resolver bounds treatment to the 09:00–21:00 business day.
        if (! $variant->exists || ! $variant->getKey() || $guests < 1 || $guests > 4
            || $durationMinutes < 1 || $durationMinutes > 720 || $startsAt->micro !== 0
            || ($roomType !== null && ! preg_match('/\A[\pL\pN][\pL\pN _-]{0,63}\z/u', $roomType))) {
            throw new InvalidArgumentException('Expected a persisted variant, 1–4 guests, 1–720 minutes, whole-second start and a valid room type.');
        }

        $eligible = ServiceVariant::query()->whereKey($variant->getKey())
            ->where('is_active', true)->whereNotNull('published_at')
            ->whereHas('service', fn (Builder $service) => $service->publiclyVisible())->exists();
        if (! $eligible) {
            return [];
        }

        $rooms = $this->rooms($variant, $guests, $roomType);
        if ($rooms->isEmpty()) {
            return [];
        }
        $start = $startsAt->utc();
        $latestEnd = $start->addMinutes($durationMinutes + $rooms->max('cleaning_minutes'));

        return $this->project($rooms, $start, $durationMinutes, $this->occupancy($rooms, $start, $latestEnd));
    }

    private function rooms(ServiceVariant $variant, int $guests, ?string $roomType): Collection
    {
        return Room::query()->where('is_active', true)->where('capacity', '>=', $guests)
            ->when($roomType !== null, fn (Builder $query) => $query->where('type', $roomType))
            ->whereHas('variants', fn (Builder $query) => $query->whereKey($variant->getKey()))
            ->orderBy('capacity')->orderBy('id')->get(['rooms.id', 'code', 'name', 'type', 'capacity', 'cleaning_minutes']);
    }

    private function occupancy(Collection $rooms, CarbonImmutable $start, CarbonImmutable $latestEnd): array
    {
        // Batch the potential overlaps, then compare each candidate's own cleaning end.
        // Query-builder rows keep stored UTC wall-clock values independent of app timezone.
        $candidates = collect(['room_reservations', 'room_blocks'])->mapWithKeys(
            fn (string $table) => [$table => DB::table($table)->whereIn('room_id', $rooms->modelKeys())
                ->when($table === 'room_reservations', fn ($query) => $query->whereNull('released_at'))
                ->where('starts_at', '<=', $latestEnd->endOfSecond()->format('Y-m-d H:i:s.u'))
                ->where('ends_at', '>=', $start->startOfSecond()->format('Y-m-d H:i:s'))
                ->get(['id', 'room_id', 'starts_at', 'ends_at'])]
        );
        $interval = new RoomBlockInterval;
        $terminal = $interval->loadTerminations($candidates['room_blocks']->pluck('id')->all());

        return ['holds' => $candidates['room_reservations']->groupBy('room_id'),
            'blocks' => $candidates['room_blocks']->groupBy('room_id'), 'terminal' => $terminal];
    }

    private function validateOccupancy(array $occupancy): void
    {
        $interval = new RoomBlockInterval;
        foreach (['holds', 'blocks'] as $kind) {
            foreach ($occupancy[$kind]->flatten(1) as $row) {
                if ($interval->instant($row, 'ends_at') <= $interval->instant($row, 'starts_at')) {
                    throw new BookingAvailabilityUnavailable;
                }
            }
        }
    }

    private function project(Collection $rooms, CarbonImmutable $start, int $durationMinutes, array $occupancy): array
    {
        ['holds' => $holds, 'blocks' => $blocks, 'terminal' => $terminal] = $occupancy;
        $interval = new RoomBlockInterval;

        return $rooms->map(function (Room $room) use ($start, $durationMinutes, $holds, $blocks, $interval, $terminal): array {
            $end = $start->addMinutes($durationMinutes + $room->cleaning_minutes);
            $occupied = $holds->get($room->id, collect())->contains(fn ($row) => $interval->overlaps($row, null, $start, $end))
                || $blocks->get($room->id, collect())->contains(fn ($row) => $interval->overlaps($row, $terminal[$row->id] ?? null, $start, $end));

            return ['code' => $room->code, 'name' => $room->name, 'type' => $room->type,
                'capacity' => $room->capacity,
                'available' => ! $occupied];
        })->all();
    }

    /**
     * Load unallocated pending requests that overlap the day's schedule window, plus
     * each eligible room's compatible variants, in two bounded read-only queries.
     *
     * @return array{bookings: list<array{variant_id: int, guests: int, room_type: ?string, starts_at: CarbonImmutable, treatment_ends_at: CarbonImmutable}>, room_variants: array<int, array<int, true>>}
     */
    private function pendingRequests(Collection $rooms, CarbonImmutable $earliestStart, CarbonImmutable $latestEnd): array
    {
        if ($rooms->isEmpty()) {
            return ['bookings' => [], 'room_variants' => []];
        }

        $rows = DB::table('bookings')
            ->where('status', 'pending')
            ->whereNotNull('starts_at')
            ->whereNotNull('treatment_ends_at')
            ->whereNotNull('service_variant_id')
            ->where('starts_at', '<=', $latestEnd->endOfSecond()->format('Y-m-d H:i:s.u'))
            ->where('treatment_ends_at', '>=', $earliestStart->startOfSecond()->format('Y-m-d H:i:s'))
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('room_reservations')
                    ->whereColumn('room_reservations.booking_id', 'bookings.id')
                    ->whereNull('room_reservations.released_at');
            })
            ->get(['id', 'service_variant_id', 'guests', 'requested_room_type', 'starts_at', 'treatment_ends_at']);

        $roomVariants = [];
        foreach (DB::table('room_service_variant')->whereIn('room_id', $rooms->modelKeys())->get(['room_id', 'service_variant_id']) as $link) {
            $roomVariants[(int) $link->room_id][(int) $link->service_variant_id] = true;
        }

        $interval = new RoomBlockInterval;
        $bookings = [];
        foreach ($rows as $row) {
            if (! RoomInput::integer($row->guests, 1, 4294967295)) {
                continue;
            }
            try {
                $startsAt = $interval->instant($row, 'starts_at');
                $treatmentEndsAt = $interval->instant($row, 'treatment_ends_at');
            } catch (ValidationException) {
                continue;
            }
            if ($treatmentEndsAt <= $startsAt) {
                continue;
            }
            $bookings[] = [
                'variant_id' => (int) $row->service_variant_id,
                'guests' => (int) $row->guests,
                'room_type' => $row->requested_room_type,
                'starts_at' => $startsAt,
                'treatment_ends_at' => $treatmentEndsAt,
            ];
        }

        return ['bookings' => $bookings, 'room_variants' => $roomVariants];
    }

    /**
     * Count pending bookings that could compete for at least one currently free room in this slot.
     *
     * @param  list<Room>  $freeRooms
     * @param  array{bookings: list<array{variant_id: int, guests: int, room_type: ?string, starts_at: CarbonImmutable, treatment_ends_at: CarbonImmutable}>, room_variants: array<int, array<int, true>>}  $pending
     */
    private function countWaitingRequests(array $freeRooms, CarbonImmutable $start, int $durationMinutes, array $pending): int
    {
        if ($freeRooms === [] || $pending['bookings'] === []) {
            return 0;
        }

        $count = 0;
        $roomVariants = $pending['room_variants'];
        foreach ($pending['bookings'] as $request) {
            foreach ($freeRooms as $room) {
                if ($room->capacity < $request['guests']) {
                    continue;
                }
                if ($request['room_type'] !== null && $room->getRawOriginal('type') !== $request['room_type']) {
                    continue;
                }
                if (! isset($roomVariants[$room->id][$request['variant_id']])) {
                    continue;
                }
                $cleaning = (int) $room->cleaning_minutes;
                if ($request['starts_at'] < $start->addMinutes($durationMinutes + $cleaning)
                    && $request['treatment_ends_at']->addMinutes($cleaning) > $start) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }
}
