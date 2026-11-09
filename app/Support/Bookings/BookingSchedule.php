<?php

namespace App\Support\Bookings;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use LogicException;
use Throwable;

final class BookingSchedule
{
    public function startsAt(Booking $booking): CarbonImmutable
    {
        $date = $booking->getRawOriginal('appointment_date');
        if ($date === null) {
            $value = $booking->appointment_date;
            $date = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
        }
        // Eloquent's date cast serializes midnight on SQLite's permissive DATE
        // column; production DATE columns return only the date component.
        $date = preg_replace('/ 00:00:00\z/', '', (string) $date);
        $raw = $date.' '.$booking->appointment_time;

        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $raw, (string) $booking->appointment_timezone);
        } catch (Throwable) {
            throw new LogicException('The saved booking schedule is invalid.');
        }

        if (! $start instanceof CarbonImmutable || $start->format('Y-m-d H:i') !== $raw) {
            throw new LogicException('The saved booking schedule is invalid.');
        }

        return $start->utc();
    }

    public function treatmentEndsAt(Booking $booking): CarbonImmutable
    {
        return $this->startsAt($booking)->addMinutes((int) $booking->total_duration_minutes);
    }
}
