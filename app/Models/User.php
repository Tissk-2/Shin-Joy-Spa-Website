<?php

namespace App\Models;

use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Send the email verification notification in a supported locale.
     */
    public function sendEmailVerificationNotification(): void
    {
        $locale = request()->hasSession()
            ? request()->session()->get('locale')
            : null;

        $this->notify(
            (new VerifyEmail)->locale(in_array($locale, ['id', 'en'], true) ? $locale : 'en'),
        );
    }

    /**
     * Send a localized password reset notification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $locale = app()->getLocale();

        $this->notify(
            (new ResetPassword((string) $token))->locale(in_array($locale, ['id', 'en'], true) ? $locale : 'en'),
        );
    }
}
