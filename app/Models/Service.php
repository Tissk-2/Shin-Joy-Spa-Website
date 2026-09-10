<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'is_vip',
        'is_active',
        'published_at',
        'lock_version',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'gallery_paths' => 'array',
            'is_vip' => 'boolean',
            'is_active' => 'boolean',
            'published_at' => 'immutable_datetime',
            'lock_version' => 'integer',
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

    public function variants(): HasMany
    {
        return $this->hasMany(ServiceVariant::class)->orderBy('display_order')->orderBy('id');
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(ServiceExtension::class)->orderBy('display_order')->orderBy('id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('display_order')->orderBy('id');
    }

    public function scopePubliclyVisible(Builder $query): void
    {
        $query->whereNotNull('published_at')
            ->where('is_active', true)
            ->whereHas('category', fn (Builder $category) => $category
                ->whereNotNull('published_at')
                ->where('is_active', true))
            ->whereHas('variants', fn (Builder $variant) => $variant
                ->whereNotNull('published_at')
                ->where('is_active', true))
            ->orderBy('display_order')
            ->orderBy('id');
    }

    public function catalogStatus(): CatalogStatus
    {
        return CatalogStatus::fromLifecycle($this->published_at, $this->is_active);
    }
}
