<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\RoomReservation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

final class AdminBookingPresenter
{
    /** @return array<string, mixed> */
    public function summary(Booking $booking, ?bool $canManage = null): array
    {
        return [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'package' => [
                'nameId' => $booking->package_name_id,
                'nameEn' => $booking->package_name_en,
                'isVip' => $booking->package_is_vip,
            ],
            'treatmentMode' => $booking->treatment_mode,
            'appointment' => [
                'date' => $booking->appointment_date->format('Y-m-d'),
                'time' => $booking->appointment_time,
                'timezone' => $booking->appointment_timezone,
            ],
            'customer' => [
                'name' => $booking->customer_name,
                'email' => $booking->customer_email,
                'phone' => $booking->customer_phone,
            ],
            'guests' => $booking->guests,
            'totalDurationMinutes' => $booking->total_duration_minutes,
            'totalPriceIdr' => $booking->total_price_idr,
            'allowedTransitions' => ($canManage ?? Gate::allows('updateStatus', $booking)) ? array_map(
                fn (BookingStatus $status): string => $status->value,
                $booking->status->allowedTransitions(),
            ) : [],
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Booking $booking): array
    {
        $reservation = Schema::hasTable('room_reservations') ? RoomReservation::with('room')->where('booking_id', $booking->getKey())->first() : null;

        return [
            ...$this->summary($booking),
            'roomSchedulingEnabled' => (bool) config('booking.scheduling_enabled'),
            'assignedRoom' => $reservation === null ? null : [
                'name' => $reservation->room->name, 'code' => $reservation->room->code,
                'type' => $reservation->room->type, 'capacity' => $reservation->room->capacity,
                'startsAt' => $reservation->starts_at->toISOString(),
                'endsAt' => $reservation->ends_at->toISOString(),
                'releasedAt' => $reservation->released_at?->toISOString(),
            ],
            'base' => [
                'choiceCode' => $booking->base_choice_code,
                'durationMinutes' => $booking->base_duration_minutes,
                'priceIdr' => $booking->base_price_idr,
            ],
            'extension' => $booking->extension_choice_code === null ? null : [
                'choiceCode' => $booking->extension_choice_code,
                'durationMinutes' => $booking->extension_duration_minutes,
                'priceIdr' => $booking->extension_price_idr,
            ],
            'notes' => $booking->notes,
            'origin' => [
                'source' => $booking->source_channel,
                'staffName' => $booking->createdByStaff?->name,
            ],
            'history' => $booking->statusChanges
                ->map(fn (BookingStatusChange $change): array => [
                    'actor' => [
                        'name' => $change->actor_name,
                        'email' => $change->actor_email,
                    ],
                    'fromStatus' => $change->from_status->value,
                    'toStatus' => $change->to_status->value,
                    'reason' => $change->reason,
                    'createdAt' => $change->created_at->toISOString(),
                    'operationalConfirmedAt' => $change->operational_confirmed_at?->toISOString(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Booking>  $paginator
     * @return array{data: list<array<string, mixed>>, links: list<array<string, mixed>>, currentPage: int, lastPage: int, total: int}
     */
    public function pagination(LengthAwarePaginator $paginator): array
    {
        // This capability is actor-wide. Observe it once for this response;
        // every actual write still reauthorizes under the staff guard.
        $first = $paginator->getCollection()->first();
        $canManage = $first !== null && Gate::allows('updateStatus', $first);
        $links = collect($paginator->linkCollection())
            ->map(fn (array $link): array => [
                ...$link,
                'label' => strip_tags(html_entity_decode((string) $link['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            ])
            ->values()
            ->all();

        return [
            'data' => $paginator->getCollection()
                ->map(fn (Booking $booking): array => $this->summary($booking, $canManage))
                ->values()
                ->all(),
            'links' => $links,
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }
}
