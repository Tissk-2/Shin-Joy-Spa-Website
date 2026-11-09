<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Support\ActionContext;
use App\Support\Bookings\BookingMutation;
use App\Support\Bookings\BookingStateWriter;
use Carbon\CarbonImmutable;
use Throwable;

final class ExpirePendingBookings
{
    public function handle(): void
    {
        $now = CarbonImmutable::now('UTC')->setMicrosecond(0);
        $window = (int) config('booking.minimum_provider_checkout_minutes'); // null → 0

        // ponytail: 200 per minute, earliest start first; raise the limit if a backlog ever builds.
        $ids = Booking::query()
            ->where('status', BookingStatus::Pending->value)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', $now->addMinutes($window)->format('Y-m-d H:i:s'))
            ->orderBy('starts_at')->orderBy('id')
            ->limit(200)
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                $this->expireIfDue((int) $id, $now);
            } catch (Throwable $exception) {
                report($exception); // one bad row must not stop the others or every later run
            }
        }
    }

    /** Re-checks the locked row; returns whether it expired the booking. */
    public function expireIfDue(int $bookingId, CarbonImmutable $now): bool
    {
        return (new BookingMutation)->run($bookingId, function (Booking $locked) use ($now): bool {
            if ($locked->status !== BookingStatus::Pending || $locked->starts_at === null) {
                return false;
            }
            $window = $locked->payment_preference === 'online'
                ? (int) config('booking.minimum_provider_checkout_minutes')
                : 0;
            if ($now->lessThan($locked->starts_at->subMinutes($window))) {
                return false;
            }

            $locked->refund_eligible_at = $now;
            $locked->refund_reason = 'expired';
            (new BookingStateWriter)->transitionLocked(
                $locked,
                BookingStatus::Cancelled,
                null,              // reason: the UI shows the System label instead
                null,              // actor: a trusted system worker
                new ActionContext('system'),
            );

            return true;
        });
    }
}
