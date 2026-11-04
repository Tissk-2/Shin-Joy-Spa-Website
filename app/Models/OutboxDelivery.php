<?php

namespace App\Models;

use App\Enums\OutboxDeliveryState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboxDelivery extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'state' => OutboxDeliveryState::class,
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'skipped_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'message_id');
    }
}
