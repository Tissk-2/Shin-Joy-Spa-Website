<?php

namespace App\Support\Rooms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class RoomWriteSchema
{
    public function ready(bool $singletons = true): bool
    {
        if ((new RoomBlockTerminationSchema)->mode() === 'partial') {
            return false;
        }
        $tables = [
            'users' => ['id', 'role', 'email_verified_at', 'can_manage_bookings'],
            'staff_access_guards' => ['id', 'revision'], 'schedule_guards' => ['id', 'revision'],
            'rooms' => ['id', 'code', 'name', 'type', 'capacity', 'cleaning_minutes', 'is_active', 'version', 'created_at', 'updated_at'],
            'room_service_variant' => ['room_id', 'service_variant_id'],
            'room_reservations' => ['id', 'booking_id', 'room_id', 'starts_at', 'treatment_ends_at', 'ends_at', 'cleaning_minutes', 'released_at', 'release_reason', 'created_at', 'updated_at'],
            'room_blocks' => ['id', 'room_id', 'starts_at', 'ends_at', 'reason', 'created_by', 'version', 'created_at', 'updated_at'],
            'bookings' => ['id', 'reference', 'guests', 'requested_room_type', 'service_id', 'service_variant_id', 'service_extension_id',
                'appointment_date', 'appointment_time', 'appointment_timezone', 'treatment_mode', 'total_duration_minutes',
                'extension_choice_code', 'extension_duration_minutes', 'extension_price_idr', 'status'],
            'booking_status_changes' => ['id', 'booking_id', 'operational_confirmed_at'],
            'service_variants' => ['id', 'service_id', 'code', 'treatment_mode', 'duration_minutes', 'is_active', 'published_at'],
            'services' => ['id', 'category_id', 'name_id', 'name_en', 'is_active', 'published_at'],
            'service_categories' => ['id', 'is_active', 'published_at'],
        ];
        foreach ($tables as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                return false;
            }
        }
        foreach ($singletons ? ['staff_access_guards', 'schedule_guards'] : [] as $table) {
            if (DB::table($table)->where('id', 1)->first() === null) {
                return false;
            }
        }

        return true;
    }

    public function requireReady(): void
    {
        // The actual guard acquisitions check singletons with current writes.
        if (! $this->ready(singletons: false)) {
            throw ValidationException::withMessages(['room' => __('admin.rooms.unavailable')]);
        }
    }
}
