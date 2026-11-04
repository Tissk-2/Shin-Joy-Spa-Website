<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Support\Outbox\BookingCreatedOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Legacy adapter for queue payloads created before the outbox switch. Nothing
 * new dispatches this job; it converts the payload into the same deduplicated
 * booking.created intent and never contacts Telegram itself.
 */
class SendTelegramBookingNotification implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $bookingId)
    {
        $this->afterCommit = true;
    }

    public function handle(BookingCreatedOutbox $outbox): void
    {
        if (! config('services.telegram.booking_notifications_enabled')) {
            return;
        }

        $booking = Booking::query()->find($this->bookingId);

        if ($booking === null) {
            $this->failPermanently('booking_missing', max(1, $this->attempts()));

            return;
        }

        DB::transaction(fn () => $outbox->record($booking));
    }

    private function failPermanently(string $category, int $attempt): void
    {
        Log::error('Telegram booking notification failed permanently.', [
            'category' => $category,
            'attempt' => $attempt,
        ]);
        $this->fail($category);
    }
}
