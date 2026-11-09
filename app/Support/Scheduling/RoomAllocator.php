<?php

namespace App\Support\Scheduling;

use App\Models\Booking;
use App\Models\RoomReservation;
use App\Support\Rooms\RoomBlockInterval;
use App\Support\Rooms\RoomBlockTerminationSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final class RoomAllocator
{
    public function allocateLocked(Booking $booking, ?int $onlyRoomId = null): RoomReservation
    {
        (new RoomBlockTerminationSchema)->requireReadable();
        $saved = $this->booking($booking);
        $existing = RoomReservation::query()->where('booking_id', $saved->id)->lockForUpdate()->first();
        if ($existing) {
            if ($existing->released_at !== null) {
                $this->fail('A released room reservation cannot be reopened.');
            }

            return $existing;
        }
        if (! in_array($saved->status, ['pending', 'confirmed'], true)) {
            $this->fail('Only pending or confirmed bookings may receive a room.');
        }
        $start = $this->start($saved);
        $this->catalog($saved);
        // Explicit locking reads are essential when a caller already has an old
        // MySQL repeatable-read snapshot. No relationship subqueries are used.
        // Future catalog/settings/inventory writers must acquire ScheduleLock
        // first too; row locks alone cannot serialize newly inserted eligibility.
        $ids = DB::table('room_service_variant')->where('service_variant_id', $saved->service_variant_id)
            ->when($onlyRoomId !== null, fn ($query) => $query->where('room_id', $onlyRoomId))
            ->orderBy('room_id')->lockForUpdate()->pluck('room_id');
        $rooms = DB::table('rooms')->whereIn('id', $ids)->where('is_active', true)
            ->where('capacity', '>=', $saved->guests)
            ->when($saved->requested_room_type !== null, fn ($q) => $q->where('type', $saved->requested_room_type))
            ->orderBy('capacity')->orderBy('id')->lockForUpdate()->get();
        foreach ($rooms as $room) {
            if (! $this->integer($room->capacity, 1, 4294967295) || ! $this->integer($room->cleaning_minutes, 0, 4294967295)) {
                $this->fail('Room capacity or cleaning duration is invalid.');
            }
            $treatmentEnd = $start->addMinutes((int) $saved->total_duration_minutes);
            $end = $treatmentEnd->addMinutes((int) $room->cleaning_minutes);
            $occupied = false;
            foreach (['room_reservations', 'room_blocks'] as $table) {
                $rows = DB::table($table)->where('room_id', $room->id)
                    ->when($table === 'room_reservations', fn ($q) => $q->whereNull('released_at'))
                    ->where('starts_at', '<=', $end->endOfSecond()->format('Y-m-d H:i:s.u'))
                    ->where('ends_at', '>=', $start->startOfSecond()->format('Y-m-d H:i:s'))
                    ->orderBy('id')->lockForUpdate()->get();
                $interval = new RoomBlockInterval;
                $terminal = $table === 'room_blocks' ? $interval->loadTerminations($rows->pluck('id')->all(), true) : [];
                $overlap = $rows->contains(fn ($row) => $interval->overlaps($row, $terminal[$row->id] ?? null, $start, $end));
                $occupied = $occupied || $overlap;
            }
            if (! $occupied) {
                return RoomReservation::create(['booking_id' => $saved->id, 'room_id' => $room->id,
                    'starts_at' => $start, 'treatment_ends_at' => $treatmentEnd,
                    'ends_at' => $end, 'cleaning_minutes' => $room->cleaning_minutes]);
            }
        }
        $this->fail('No compatible room is available for the whole group.');
    }

    public function releaseLocked(Booking $booking, string $reason, CarbonImmutable $releasedAt): void
    {
        $saved = $this->booking($booking);
        if (trim($reason) === '' || mb_strlen($reason) > 255 || $releasedAt->micro !== 0) {
            $this->fail('Release requires a reason up to 255 characters and a whole-second timestamp.');
        }
        $reservation = RoomReservation::query()->where('booking_id', $saved->id)->lockForUpdate()->first();
        if (! $reservation || $reservation->released_at !== null) {
            return;
        }
        if ($releasedAt->greaterThanOrEqualTo($reservation->starts_at)) {
            $this->fail('A room cannot be released at or after its reserved start.');
        }
        $reservation->update(['released_at' => $releasedAt->utc(), 'release_reason' => $reason]);
    }

    private function booking(Booking $booking): object
    {
        (new ScheduleLock)->assertHeld();
        if (! $booking->exists || ! $booking->getKey() || $booking->getConnection() !== DB::connection()) {
            throw new LogicException('Expected a saved booking on the default connection.');
        }
        $saved = DB::table('bookings')->where('id', $booking->getKey())->lockForUpdate()->first();
        if (! $saved) {
            $this->fail('The booking no longer exists.');
        }

        return $saved;
    }

    private function start(object $saved): CarbonImmutable
    {
        // Eloquent's date cast serializes midnight on SQLite's permissive DATE
        // column; production DATE columns return only the date component.
        $date = preg_replace('/ 00:00:00\z/', '', $saved->appointment_date);
        $type = $saved->requested_room_type;
        if (! $this->integer($saved->guests, 1, 4) || ! $this->integer($saved->total_duration_minutes, 1, 720)
            || $saved->appointment_timezone !== 'Asia/Jakarta'
            || ($type !== null && ! preg_match('/\A[\pL\pN][\pL\pN _-]{0,63}\z/u', $type))
            || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)
            || ! preg_match('/\A(?:09|1[0-9]|20):(?:00|30)\z/', $saved->appointment_time)
            || $saved->appointment_time > '20:00') {
            $this->fail('The saved booking schedule or group size is invalid.');
        }
        try {
            $raw = $date.' '.$saved->appointment_time;
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $raw, 'Asia/Jakarta');
            if ($start->format('Y-m-d H:i') !== $raw || $start->addMinutes((int) $saved->total_duration_minutes)->greaterThan($start->setTime(21, 0))) {
                $this->fail('The saved treatment must finish by 21:00.');
            }

            return $start->utc();
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }
            $this->fail('The saved booking date is invalid.');
        }
    }

    private function catalog(object $saved): void
    {
        $service = $this->visible('services', $saved->service_id);
        $this->visible('service_categories', $service->category_id);
        $variant = $this->visible('service_variants', $saved->service_variant_id);
        if ((int) $variant->service_id !== (int) $saved->service_id || $variant->treatment_mode !== $saved->treatment_mode) {
            $this->fail('The saved treatment references are inconsistent.');
        }
        // Deleting catalog extensions nulls their FK while retaining the quote.
        // Any saved selection metadata still requires its original reference.
        $hasExtension = $saved->service_extension_id !== null || $saved->extension_choice_code !== null
            || $saved->extension_duration_minutes !== null || $saved->extension_price_idr !== null;
        if ($hasExtension) {
            if ($saved->service_extension_id === null || $saved->extension_choice_code === null
                || trim($saved->extension_choice_code) === '' || $saved->extension_duration_minutes === null
                || $saved->extension_price_idr === null) {
                $this->fail('The saved extension selection is incomplete or its reference is missing.');
            }
            $extension = $this->visible('service_extensions', $saved->service_extension_id);
            if ((int) $extension->service_id !== (int) $saved->service_id || $extension->treatment_mode !== $saved->treatment_mode) {
                $this->fail('The saved extension references are inconsistent.');
            }
        }
    }

    private function visible(string $table, mixed $id): object
    {
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        if (! $row || ! $row->is_active || $row->published_at === null) {
            $this->fail('The referenced treatment catalog is no longer available.');
        }

        return $row;
    }

    private function integer(mixed $value, int $min, int $max): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && $value >= $min && $value <= $max;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['room' => $message]);
    }
}
