<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramEnrollment extends Model
{
    protected $fillable = [
        'user_id',
        'issued_by_user_id',
        'token_hash',
        'expires_at',
        'claimed_telegram_user_id',
        'claimed_at',
        'activated_at',
        'activated_by_user_id',
        'cancelled_at',
    ];

    /** The hash never leaves the server, even when this model is serialized for the staff page. */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
