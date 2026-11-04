<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class Room extends Model
{
    protected $fillable = ['code', 'name', 'type', 'capacity', 'is_active', 'cleaning_minutes', 'version'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'is_active' => 'boolean', 'cleaning_minutes' => 'integer', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (Room $room): void {
            if ($room->capacity < 1 || $room->cleaning_minutes < 0) {
                throw new InvalidArgumentException('Room capacity must be positive and cleaning minutes nonnegative.');
            }
        });
    }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(ServiceVariant::class, 'room_service_variant');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(RoomReservation::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(RoomBlock::class);
    }
}
