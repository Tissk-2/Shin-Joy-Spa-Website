<?php

namespace App\Support\Scheduling;

use App\Support\Rooms\RoomBlockTerminationSchema;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class BookingAvailabilitySchema
{
    public function requireReadable(): void
    {
        foreach ([
            'booking_settings' => ['id', 'version', 'review_intervals', 'review_target_minutes',
                'booking_horizon_days', 'minimum_lead_minutes', 'minimum_checkout_minutes'],
            'rooms' => ['id', 'code', 'name', 'type', 'capacity', 'is_active', 'cleaning_minutes'],
            'room_service_variant' => ['room_id', 'service_variant_id'],
            'room_reservations' => ['id', 'room_id', 'starts_at', 'ends_at', 'released_at'],
            'room_blocks' => ['id', 'room_id', 'starts_at', 'ends_at'],
            'services' => ['id', 'slug', 'category_id', 'is_active', 'published_at', 'display_order'],
            'service_categories' => ['id', 'is_active', 'published_at'],
            'service_variants' => ['id', 'service_id', 'treatment_mode', 'duration_minutes', 'is_active', 'published_at', 'display_order'],
            'service_extensions' => ['id', 'service_id', 'code', 'treatment_mode', 'duration_minutes', 'is_active', 'published_at', 'display_order'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                throw new BookingAvailabilityUnavailable;
            }
        }
        try {
            (new RoomBlockTerminationSchema)->requireReadable();
        } catch (ValidationException $error) {
            throw new BookingAvailabilityUnavailable(previous: $error);
        }
    }
}
