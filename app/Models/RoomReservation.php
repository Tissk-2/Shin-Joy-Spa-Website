<?php

namespace App\Models;

use App\Support\Scheduling\UsesUtcScheduleTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomReservation extends Model
{
    use UsesUtcScheduleTimestamps;

    protected $fillable = ['booking_id', 'room_id', 'starts_at', 'treatment_ends_at', 'ends_at', 'cleaning_minutes', 'released_at', 'release_reason'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'treatment_ends_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'cleaning_minutes' => 'integer', 'released_at' => 'immutable_datetime'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
