<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        $actor = $this->current($user);

        return $actor?->hasVerifiedEmail() && $actor->isAdmin();
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->viewAny($user);
    }

    public function updateStatus(User $user, Booking $booking): bool
    {
        $actor = $this->current($user, lock: true);
        if (! $actor?->hasVerifiedEmail() || ! $actor->isAdmin()) {
            return false;
        }
        $guard = Schema::hasTable('staff_access_guards');
        $capability = Schema::hasColumn('users', 'can_manage_bookings');
        if (! $guard && ! $capability) {
            return ! config('booking.scheduling_enabled') && ! User::where('role', 'owner')->exists();
        }

        return $guard && $capability && DB::table('staff_access_guards')->where('id', 1)->exists()
            && ($actor->isOwner() || $actor->can_manage_bookings === true);
    }

    public function approve(User $user, Booking $booking): bool
    {
        return $this->updateStatus($user, $booking);
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $this->updateStatus($user, $booking);
    }

    public function complete(User $user, Booking $booking): bool
    {
        return $this->updateStatus($user, $booking);
    }

    public function noShow(User $user, Booking $booking): bool
    {
        return $this->updateStatus($user, $booking);
    }

    private function current(User $user, bool $lock = false): ?User
    {
        return $user->exists && $user->getKey() && $user->getConnection() === DB::connection()
            ? User::whereKey($user->getKey())->when($lock && DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first() : null;
    }
}
