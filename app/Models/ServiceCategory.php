<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCategory extends Model
{
    protected $fillable = [
        'slug',
        'name_id',
        'name_en',
        'image_path',
        'published_at',
        'lock_version',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'published_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('display_order')->orderBy('id');
    }

    public function scopePubliclyVisible(Builder $query): void
    {
        $query->whereNotNull('published_at')
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('id');
    }

    public function catalogStatus(): CatalogStatus
    {
        return CatalogStatus::fromLifecycle($this->published_at, $this->is_active);
    }
}
