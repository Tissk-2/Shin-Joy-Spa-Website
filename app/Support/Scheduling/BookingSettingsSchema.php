<?php

namespace App\Support\Scheduling;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class BookingSettingsSchema
{
    public function ready(): bool
    {
        foreach ([
            'booking_settings' => ['id', 'review_intervals', 'review_target_minutes', 'booking_horizon_days',
                'minimum_lead_minutes', 'minimum_checkout_minutes', 'version', 'created_at', 'updated_at'],
            'users' => ['id', 'role', 'email_verified_at', 'can_manage_bookings'],
            'staff_access_guards' => ['id', 'revision'], 'schedule_guards' => ['id', 'revision'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                return false;
            }
        }

        return true;
    }

    public function requireReady(): void
    {
        if (! $this->ready()) {
            throw ValidationException::withMessages(['settings' => __('admin.booking_settings.unavailable')]);
        }
    }
}
