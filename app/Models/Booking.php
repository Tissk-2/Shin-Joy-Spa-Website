<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected $guarded = ['*'];

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

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(BookingStatusChange::class)
            ->orderBy('created_at')
            ->orderBy('id');
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
