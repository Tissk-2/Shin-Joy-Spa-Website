<?php

namespace App\Support;

use App\Models\User;

final class AdminStaffPresenter
{
    /** @return array<string, mixed> */
    public function summary(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role->value,
            'verified' => $user->hasVerifiedEmail(), 'canManageBookings' => $user->isOwner() || $user->can_manage_bookings === true,
        ];
    }
}
