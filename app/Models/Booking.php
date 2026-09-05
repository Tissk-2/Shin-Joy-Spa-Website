<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'service_id',
        'service_variant_id',
        'service_extension_id',
        'reference',
        'submission_key',
        'request_fingerprint',
        'status',
        'customer_name',
        'customer_email',
        'customer_phone',
        'appointment_date',
        'appointment_time',
        'appointment_timezone',
        'guests',
        'notes',
        'package_slug',
        'package_name_id',
        'package_name_en',
        'package_is_vip',
        'treatment_mode',
        'base_choice_code',
        'base_duration_minutes',
        'base_price_idr',
        'extension_choice_code',
        'extension_duration_minutes',
        'extension_price_idr',
        'total_duration_minutes',
        'total_price_idr',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'appointment_date' => 'date',
            'package_is_vip' => 'boolean',
            'guests' => 'integer',
            'base_duration_minutes' => 'integer',
            'base_price_idr' => 'integer',
            'extension_duration_minutes' => 'integer',
            'extension_price_idr' => 'integer',
            'total_duration_minutes' => 'integer',
            'total_price_idr' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function serviceVariant(): BelongsTo
    {
        return $this->belongsTo(ServiceVariant::class);
    }

    public function serviceExtension(): BelongsTo
    {
        return $this->belongsTo(ServiceExtension::class);
    }
}
