<?php

namespace App\Support\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\User;
use App\Support\ActionContext;
use App\Support\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;

final class BookingStateWriter
{
    public function transitionLocked(
        Booking $booking,
        BookingStatus $next,
        ?string $reason,
        ?User $actor,
        ActionContext $context,
        ?CarbonImmutable $operationalConfirmedAt = null,
    ): Booking {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Booking state transitions require an active database transaction.');
        }

        if (! $booking->status->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'nextStatus' => __('admin.status.invalid'),
            ]);
        }

        $from = $booking->status;
        $booking->status = $next;
        $booking->booking_version = (int) $booking->booking_version + 1;
        $booking->save();

        BookingStatusChange::query()->forceCreate([
            'booking_id' => $booking->getKey(),
            'admin_user_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_type' => $actor === null ? 'system' : 'staff',
            'channel' => $context->channel,
            'external_actor_id' => $context->externalActorId,
            ...(Schema::hasColumn('booking_status_changes', 'operational_confirmed_at') ? ['operational_confirmed_at' => $operationalConfirmedAt] : []),
            'from_status' => $from,
            'to_status' => $next,
            'reason' => $reason,
            'ip_address' => $context->ipAddress,
            'user_agent' => $context->userAgent,
        ]);

        $type = $this->eventType($from, $next);
        app(OutboxWriter::class)->record(
            $type,
            "{$type}:{$booking->getKey()}:{$booking->booking_version}",
            ['booking_id' => $booking->getKey(), 'booking_version' => $booking->booking_version],
        );

        return $booking;
    }

    private function eventType(BookingStatus $from, BookingStatus $next): string
    {
        return match (true) {
            $from === BookingStatus::Pending && in_array($next, [BookingStatus::Confirmed, BookingStatus::AwaitingPayment], true) => 'booking.approved',
            $from === BookingStatus::AwaitingPayment && $next === BookingStatus::Confirmed => 'booking.confirmed',
            $next === BookingStatus::Cancelled => 'booking.cancelled',
            $next === BookingStatus::Completed => 'booking.completed',
            $next === BookingStatus::NoShow => 'booking.no_show',
            default => throw new LogicException("No lifecycle event is defined for [{$from->value} -> {$next->value}]."),
        };
    }
}
