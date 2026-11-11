<?php

namespace App\Support\Payments;

use App\Models\Booking;

final class BookingPaymentSummary
{
    /**
     * What the website and emails may say about a booking's payment. Stage C has no payment records, so it never
     * claims money was collected or returned; stage D extends this with its payments and refunds.
     *
     * @return array{state: string, deadline: ?string, checkout_url: ?string, refunds: list<array<string, mixed>>}
     */
    public function forBooking(Booking $booking): array
    {
        return [
            'state' => $booking->payment_preference === 'at_spa' ? 'at_spa_unrecorded' : 'unknown',   // mutation S
            'deadline' => $booking->payment_deadline_at?->toIso8601String(),
            'checkout_url' => null,
            'refunds' => [],
        ];
    }
}
