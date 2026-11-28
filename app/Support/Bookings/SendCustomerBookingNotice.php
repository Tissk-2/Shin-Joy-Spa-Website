<?php

namespace App\Support\Bookings;

use App\Contracts\OutboxHandler;
use App\Enums\BookingStatus;
use App\Exceptions\Outbox\OutboxPermanentException;
use App\Exceptions\Outbox\OutboxRetryableException;
use App\Exceptions\Outbox\OutboxSkippedException;
use App\Models\Booking;
use App\Models\OutboxMessage;
use App\Notifications\BookingStatusNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class SendCustomerBookingNotice implements OutboxHandler
{
    /** The status each event announces, and the notice that announces it. */
    private const NOTICES = [
        'booking.received' => [BookingStatus::Pending, 'received'],
        'booking.approved' => [BookingStatus::Confirmed, 'approved'],
        'booking.cancelled' => [BookingStatus::Cancelled, 'cancelled'],
        'booking.no_show' => [BookingStatus::NoShow, 'no_show'],
    ];

    public function handle(OutboxMessage $message): void
    {
        [$status, $kind] = self::NOTICES[$message->type] ?? throw new OutboxPermanentException('event');
        $bookingId = $message->payload['booking_id'] ?? null;
        $version = $message->payload['booking_version'] ?? null;
        // The receipt's event is recorded at creation and carries no version.
        $receipt = $kind === 'received';

        if (! is_int($bookingId) || $bookingId <= 0 || (! $receipt && (! is_int($version) || $version <= 0))) {
            throw new OutboxPermanentException('payload');
        }

        if (config('booking.customer_notices_enabled') !== true) {   // mutation F
            throw new OutboxSkippedException('disabled');
        }

        $booking = Booking::query()->find($bookingId) ?? throw new OutboxPermanentException('booking_missing');

        // A newer decision superseded this one, and its own notice tells the customer where things stand.
        // A receipt is only worth sending while staff have not decided yet.
        if ($receipt ? $booking->status !== BookingStatus::Pending : $booking->booking_version !== $version) {   // mutation V
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

        // The expiry job and auto-decline system cancellations read differently from a manual staff decline.
        if ($kind === 'cancelled' && $booking->refund_reason === 'expired') {   // mutation X
            $kind = 'expired';
        } elseif ($kind === 'cancelled' && $booking->refund_reason === 'slot_full') {
            $kind = 'slot_full';
        }

        // Sent at once: the outbox already retries.
        try {
            Notification::route('mail', $email)->notify((new BookingStatusNotification($booking, $kind))->locale($locale));
        } catch (TransportExceptionInterface $exception) {
            // The exception's message can hold the customer's address and the mail host, so it is never logged.
            Log::warning('Customer booking notice delivery failed.', [
                'category' => 'mail',
                'booking_reference' => $booking->reference,
                'event' => $message->type,
                'exception' => $exception::class,
                'code' => $exception->getCode(),
            ]);

            throw new OutboxRetryableException('mail');   // mutation M
        }
    }
}
