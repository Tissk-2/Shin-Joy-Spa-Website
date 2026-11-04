<?php

namespace App\Support\Rooms;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RoomBlockActor
{
    public function current(?User $actor, bool $lock = false): ?User
    {
        if (! $actor?->exists || ! $actor->getKey() || $actor->getConnection() !== DB::connection()
            || ! Schema::hasColumns('users', ['id', 'role', 'email_verified_at', 'can_manage_bookings'])) {
            return null;
        }
        $current = User::query()->whereKey($actor->getKey())->when($lock, fn ($q) => $q->lockForUpdate())->first();

        return $current?->hasVerifiedEmail() && ($current->isOwner()
            || ($current->role === UserRole::Admin && $current->can_manage_bookings === true)) ? $current : null;
    }

    public function authorize(?User $actor, bool $lock = false): User
    {
        return $this->current($actor, $lock) ?? throw new AuthorizationException;
    }
}
