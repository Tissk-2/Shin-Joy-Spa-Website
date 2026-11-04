<?php

namespace App\Support\Rooms;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class RoomOwner
{
    public function current(?User $user, bool $lock = false): ?User
    {
        if (! $user?->exists || ! $user->getKey() || $user->getConnection() !== DB::connection()) {
            return null;
        }
        $current = User::query()->whereKey($user->getKey())->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $current?->isOwner() && $current->hasVerifiedEmail() ? $current : null;
    }

    public function authorize(?User $user, bool $lock = false): User
    {
        return $this->current($user, $lock) ?? throw new AuthorizationException;
    }
}
