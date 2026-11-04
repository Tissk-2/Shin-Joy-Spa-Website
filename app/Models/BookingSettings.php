<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingSettings extends Model
{
    public $incrementing = false;

    protected $fillable = ['id', 'review_intervals', 'review_target_minutes', 'booking_horizon_days',
        'minimum_lead_minutes', 'minimum_checkout_minutes', 'version'];

    protected function casts(): array
    {
        // Integer casts would hide malformed persisted facts from validation.
        return ['review_intervals' => 'array'];
    }
}
