<?php

namespace App\Support\Bookings;

use App\Contracts\OutboxHandler;
use App\Enums\BookingStatus;
use App\Exceptions\Outbox\OutboxPermanentException;
use App\Exceptions\Outbox\OutboxSkippedException;
use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Notifications\BookingStatusNotification;
use Illuminate\Support\Facades\Notification;

final class SendCustomerBookingNotice implements OutboxHandler
{
    /** The status each event announces, and the notice that announces it. */
    private const NOTICES = [
        'booking.approved' => [BookingStatus::Confirmed, 'approved'],
        'booking.cancelled' => [BookingStatus::Cancelled, 'cancelled'],
        'booking.no_show' => [BookingStatus::NoShow, 'no_show'],
    ];

    public function handle(OutboxMessage $message): void
    {
        [$status, $kind] = self::NOTICES[$message->type] ?? throw new OutboxPermanentException('event');
        $bookingId = $message->payload['booking_id'] ?? null;
        $version = $message->payload['booking_version'] ?? null;

        if (! is_int($bookingId) || $bookingId <= 0 || ! is_int($version) || $version <= 0) {
            throw new OutboxPermanentException('payload');
        }

        if (config('booking.customer_notices_enabled') !== true) {   // mutation F
            throw new OutboxSkippedException('disabled');
        }

        $booking = Booking::query()->find($bookingId) ?? throw new OutboxPermanentException('booking_missing');

        // A newer decision superseded this one, and its own notice tells the customer where things stand.
        if ($booking->booking_version !== $version) {   // mutation V
            throw new OutboxSkippedException('superseded');
        }

        // Only an online approval can differ at the same version: it awaits payment, which stage D announces.
        if ($booking->status !== $status) {   // mutation A
            throw new OutboxSkippedException('awaiting_payment');
        }

        $email = trim((string) $booking->customer_email);

        if ($email === '') {   // mutation E
            throw new OutboxSkippedException('no_email');   // a staff-entered booking without an address
        }

        $locale = in_array($booking->locale, ['id', 'en'], true) ? $booking->locale : 'id';   // mutation Lc

        // Sent at once: the outbox already retries, and a mail transport failure retries as "unexpected".
        Notification::route('mail', $email)->notify((new BookingStatusNotification($booking, $kind))->locale($locale));
    }
}
