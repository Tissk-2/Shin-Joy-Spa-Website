<?php

namespace App\Support\Outbox;

use App\Models\Booking;
use App\Models\OutboxMessage;

final class BookingCreatedOutbox
{
    public function record(Booking $booking): OutboxMessage
    {
        return app(OutboxWriter::class)->record(
            'booking.created',
            'booking.created:'.$booking->id,
            ['booking_id' => $booking->id],
        );
    }
}
