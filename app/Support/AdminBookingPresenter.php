<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Pagination\LengthAwarePaginator;

final class AdminBookingPresenter
{
    /** @return array<string, mixed> */
    public function summary(Booking $booking): array
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
            'allowedTransitions' => array_map(
                fn (BookingStatus $status): string => $status->value,
                $booking->status->allowedTransitions(),
            ),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Booking>  $paginator
     * @return array{data: list<array<string, mixed>>, links: list<array<string, mixed>>, currentPage: int, lastPage: int, total: int}
     */
    public function pagination(LengthAwarePaginator $paginator): array
    {
        $links = collect($paginator->linkCollection())
            ->map(fn (array $link): array => [
                ...$link,
                'label' => strip_tags(html_entity_decode((string) $link['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            ])
            ->values()
            ->all();

        return [
            'data' => $paginator->getCollection()
                ->map(fn (Booking $booking): array => $this->summary($booking))
                ->values()
                ->all(),
            'links' => $links,
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }
}
