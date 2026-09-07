<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

final class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->isAdmin();
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->viewAny($user);
    }

    public function updateStatus(User $user, Booking $booking): bool
    {
        return $this->viewAny($user);
    }
}
