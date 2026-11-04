<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use App\Enums\PricingBasis;
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
        'pricing_basis',
        'min_guests',
        'max_guests',
        'pricing_verified_at',
        'pricing_verified_by',
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
            'pricing_basis' => PricingBasis::class,
            'min_guests' => 'integer',
            'max_guests' => 'integer',
            'pricing_verified_at' => 'immutable_datetime',
            'pricing_verified_by' => 'integer',
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

    public function pricingVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pricing_verified_by');
    }

    public function hasVerifiedPricing(): bool
    {
        return $this->pricing_basis !== null
            && $this->min_guests !== null
            && $this->max_guests !== null
            && $this->pricing_verified_at !== null
            && $this->pricing_verified_by !== null;
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

    public function scopeWithPublicChoices(Builder $query): void
    {
        $query->with([
            'variants' => fn (HasMany $variants) => $variants->active(),
            'extensions' => fn (HasMany $extensions) => $extensions->active(),
        ]);
    }

    public function catalogStatus(): CatalogStatus
    {
        return CatalogStatus::fromLifecycle($this->published_at, $this->is_active);
    }
}
