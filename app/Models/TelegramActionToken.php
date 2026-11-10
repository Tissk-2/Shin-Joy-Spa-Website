<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramActionToken extends Model
{
    protected $fillable = [
        'telegram_booking_message_id',
        'booking_id',
        'booking_version',
        'action',
        'nonce_hash',
        'initiating_user_id',
        'initiating_telegram_user_id',
        'expires_at',
        'consumed_at',
        'result',
    ];

    /** The hash never leaves the server, even when this model is serialized. */
    protected $hidden = ['nonce_hash'];

    protected function casts(): array
    {
        return [
            'booking_version' => 'integer',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
