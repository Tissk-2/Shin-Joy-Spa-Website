<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\OutboxDelivery;
use App\Models\OutboxMessage;
use App\Models\RoomReservation;
use App\Models\TelegramBookingMessage;
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
            'telegramMessage' => $this->telegramMessage($booking),
            'notifications' => $this->notifications($booking),
        ];
    }

    /**
     * The staff Telegram message, so staff can tell whether the chat shows this booking's current status.
     *
     * @return array{state: string, current: bool}|null
     */
    private function telegramMessage(Booking $booking): ?array
    {
        $row = TelegramBookingMessage::query()->where('booking_id', $booking->getKey())->orderByDesc('id')->first();   // mutation Q5

        return $row === null ? null : [
            'state' => $row->state,   // sending | sent | deleted
            'current' => $row->booking_version === $booking->booking_version,   // mutation Q4
        ];
    }

    /**
     * Every notice this booking's events produced, oldest first: the staff Telegram message and the customer email.
     * `detail` is the delivery's sanitized last_error, such as "skipped: no_email" or "permanent: telegram_http".
     *
     * @return list<array{event: string, channel: string, state: string, detail: ?string, attempts: int, updatedAt: string}>
     */
    private function notifications(Booking $booking): array
    {
        // Outbox keys are booking.created:<id> and <event>:<id>:<version>. Their hashes are unique and indexed, so
        // this never scans the outbox.
        $keys = ['booking.created:'.$booking->getKey()];   // mutation Q1
        foreach (['booking.approved', 'booking.confirmed', 'booking.cancelled', 'booking.completed', 'booking.no_show'] as $event) {
            for ($version = 2; $version <= $booking->booking_version; $version++) {   // mutation Q2
                $keys[] = "{$event}:{$booking->getKey()}:{$version}";
            }
        }

        return OutboxMessage::query()
            ->whereIn('deduplication_hash', array_map(fn (string $key): string => hash('sha256', $key), $keys))
            ->with(['deliveries' => fn ($query) => $query->whereIn('handler_key', ['telegram', 'customer'])->orderBy('id')])   // mutation Q3
            ->orderBy('id')
            ->get()
            ->flatMap(fn (OutboxMessage $message) => $message->deliveries->map(fn (OutboxDelivery $delivery): array => [
                'event' => $message->type,
                'channel' => $delivery->handler_key,
                'state' => $delivery->state->value,
                'detail' => $delivery->last_error,
                'attempts' => $delivery->attempts,
                'updatedAt' => $delivery->updated_at->toISOString(),
            ]))
            ->values()
            ->all();
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
