<?php

namespace App\Models;

use App\Support\Scheduling\UsesUtcScheduleTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomBlock extends Model
{
    use UsesUtcScheduleTimestamps;

    protected $fillable = ['room_id', 'starts_at', 'ends_at', 'reason', 'created_by', 'version'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
