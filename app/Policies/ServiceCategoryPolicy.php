<?php

namespace App\Policies;

use App\Models\ServiceCategory;
use App\Models\User;

final class ServiceCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function view(User $user, ServiceCategory $category): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function update(User $user, ServiceCategory $category): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function publish(User $user, ServiceCategory $category): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function archive(User $user, ServiceCategory $category): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function restore(User $user, ServiceCategory $category): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    public function delete(User $user, ServiceCategory $category): bool
    {
        return $this->isVerifiedAdmin($user);
    }

    private function isVerifiedAdmin(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->isAdmin();
    }
}
