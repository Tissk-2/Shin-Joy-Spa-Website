<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model
{
    protected $fillable = [
        'category_id',
        'slug',
        'name_id',
        'name_en',
        'description_id',
        'description_en',
        'long_description_id',
        'long_description_en',
        'good_for_id',
        'good_for_en',
        'duration_minutes',
        'price_idr',
        'image_path',
        'gallery_paths',
        'popularity',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'gallery_paths' => 'array',
            'is_active' => 'boolean',
            'duration_minutes' => 'integer',
            'price_idr' => 'integer',
            'popularity' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('display_order')->orderBy('id');
    }

    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereHas('category', fn (Builder $categoryQuery) => $categoryQuery->where('is_active', true))
            ->orderBy('display_order')
            ->orderBy('id');
    }
}
