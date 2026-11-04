<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutboxMessage extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'schema_version' => 'integer',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(OutboxDelivery::class, 'message_id');
    }
}
