<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The staff Telegram message for one booking in one chat. `state` is `sending` until Telegram confirms a copy, then
 * `sent`; `deleted` once an edit found the message gone, after which it is never edited again.
 */
class TelegramBookingMessage extends Model
{
    protected $fillable = [
        'booking_id',
        'chat_id',
        'message_thread_id',
        'message_id',
        'booking_version',
        'state',
        'render_key',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'message_thread_id' => 'integer',
            'message_id' => 'integer',
            'booking_version' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
