<?php

namespace App\Models;

use App\Enums\TreatmentMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceExtension extends Model
{
    protected $fillable = [
        'service_id',
        'code',
        'treatment_mode',
        'duration_minutes',
        'price_idr',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'treatment_mode' => TreatmentMode::class,
            'duration_minutes' => 'integer',
            'price_idr' => 'integer',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('display_order')->orderBy('id');
    }
}
