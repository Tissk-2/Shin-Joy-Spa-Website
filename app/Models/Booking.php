<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PricingBasis;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    /** Lifecycle instants are stored as UTC; appointment_date is a Jakarta calendar day and stays as given. */
    private const UTC_INSTANTS = [
        'starts_at', 'treatment_ends_at', 'approved_at', 'payment_deadline_at', 'review_due_at', 'refund_eligible_at',
    ];

    protected $guarded = ['*'];

    public function setAttribute($key, $value)
    {
        if ($value instanceof DateTimeInterface && in_array($key, self::UTC_INSTANTS, true)) {
            $value = CarbonImmutable::instance($value)->utc();
        }

        return parent::setAttribute($key, $value);
    }

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
            'pricing_basis' => PricingBasis::class,
            'min_guests' => 'integer',
            'max_guests' => 'integer',
            'booking_version' => 'integer',
            'starts_at' => 'immutable_datetime',
            'treatment_ends_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'payment_deadline_at' => 'immutable_datetime',
            'review_due_at' => 'immutable_datetime',
            'refund_eligible_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_staff_id');
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
