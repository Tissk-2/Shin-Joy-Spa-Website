<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramStaffLink extends Model
{
    protected $fillable = ['user_id', 'telegram_user_id', 'enrollment_id', 'activated_at'];

    protected function casts(): array
    {
        return ['activated_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
