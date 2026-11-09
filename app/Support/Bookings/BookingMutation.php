<?php

namespace App\Support\Bookings;

use App\Models\Booking;
use App\Support\Scheduling\ScheduleLock;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BookingMutation
{
    /**
     * The caller owns authorization. This only establishes the schedule guard
     * (when the room schema exists), then reloads the booking under a row lock.
     */
    public function run(int $bookingId, Closure $operation): mixed
    {
        $execute = fn (): mixed => $operation(
            Booking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail(),
        );

        return Schema::hasTable('room_reservations')
            ? (new ScheduleLock)->run($execute)
            : DB::transaction($execute);
    }
}
