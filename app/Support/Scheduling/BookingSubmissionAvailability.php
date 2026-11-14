<?php

namespace App\Support\Scheduling;

use App\Queries\RoomAvailabilityQuery;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class BookingSubmissionAvailability
{
    public function __construct(private readonly RoomAvailabilityQuery $availability) {}

    /** Current observation only: pending submissions do not hold or reserve a room. */
    public function validate(array $data, bool $staffEntry = false): void
    {
        if (config('booking.scheduling_enabled') !== true) {
            return;
        }

        $time = $data['appointment_time'] ?? null;
        if (! is_string($time) || ! preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $time)) {
            $this->unavailableTime();
        }

        try {
            $result = $this->availability->forSelection([
                'service' => $data['service'] ?? null,
                'mode' => $data['mode'] ?? null,
                'duration' => $data['duration'] ?? null,
                'extension' => $data['extension'] ?? null,
                'date' => $data['appointment_date'] ?? null,
                'guests' => $data['guests'] ?? null,
            ], $staffEntry);
        } catch (BookingAvailabilityUnavailable) {
            throw ValidationException::withMessages(['time' => __('validation.booking.availability_unavailable')]);
        }

        // forSelection has validated the date and generates the allowed half-hour grid.
        $requestedStart = CarbonImmutable::createFromFormat('!Y-m-d H:i', $data['appointment_date'].' '.$time, 'Asia/Jakarta')
            ->utc()->toISOString();
        foreach ($result['slots'] as $slot) {
            if ($slot['start_at'] === $requestedStart) {
                foreach ($slot['rooms'] as $room) {
                    if ($room['available'] === true) {
                        return;
                    }
                }
            }
        }

        $this->unavailableTime();
    }

    private function unavailableTime(): never
    {
        throw ValidationException::withMessages(['time' => __('validation.booking.slot_unavailable')]);
    }
}
