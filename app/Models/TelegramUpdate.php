<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramUpdate extends Model
{
    public $timestamps = false;

    /** The deep-link token is a short-lived secret; never serialize it. */
    protected $hidden = ['start_token'];

    protected function casts(): array
    {
        return [
            'update_id' => 'integer',
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
