<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

final class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function view(User $user, Service $service): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function update(User $user, Service $service): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function publish(User $user, Service $service): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function archive(User $user, Service $service): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function restore(User $user, Service $service): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    private function isVerifiedAdmin(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->isAdmin();
    }
}
