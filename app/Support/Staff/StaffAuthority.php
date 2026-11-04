<?php

namespace App\Support\Staff;

use App\Enums\UserRole;
use App\Exceptions\FinalAdministratorException;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class StaffAuthority
{
    public static function confirmedTarget(int $id, string $email, bool $verified): User
    {
        (new StaffAccessLock)->assertHeld();
        $user = User::query()->whereKey($id)->lockForUpdate()->first();
        if (! $user || $user->email !== $email || ($verified && ! $user->hasVerifiedEmail())) {
            throw new StaffTargetException('The target account changed after confirmation. Review the account and run the command again.');
        }

        return $user;
    }

    public static function protectDeletion(User $user): void
    {
        (new StaffAccessLock)->assertHeld();
        $staff = self::currentStaff();
        if ($user->isOwner() && ($staff->filter(fn (User $member) => $member->isOwner())->count() <= 1
            || ($user->hasVerifiedEmail() && self::verifiedOwners($staff) <= 1))) {
            throw ValidationException::withMessages(['password' => __('auth.final_owner')]);
        }
        self::assertFinalAdmin($user, $staff);
    }

    public static function protectEmailChange(User $user): void
    {
        (new StaffAccessLock)->assertHeld();
        if ($user->isOwner() && $user->hasVerifiedEmail() && self::verifiedOwners(self::currentStaff()) <= 1) {
            throw ValidationException::withMessages(['email' => __('auth.final_owner_email')]);
        }
    }

    public static function protectFinalAdmin(User $user): void
    {
        (new StaffAccessLock)->assertHeld();
        self::assertFinalAdmin($user, self::currentStaff());
    }

    private static function assertFinalAdmin(User $user, Collection $staff): void
    {
        if ($user->role === UserRole::Admin && self::verifiedOwners($staff) === 0
            && $staff->filter(fn (User $member) => $member->role === UserRole::Admin)->count() <= 1) {
            throw new FinalAdministratorException;
        }
    }

    /** @return Collection<int, User> */
    private static function currentStaff(): Collection
    {
        // Locking reads see the current committed rows even when a MySQL caller
        // already established a REPEATABLE READ snapshot in an outer transaction.
        return User::whereIn('role', [UserRole::Owner, UserRole::Admin])->orderBy('id')
            ->lockForUpdate()->get(['id', 'role', 'email_verified_at']);
    }

    private static function verifiedOwners(Collection $staff): int
    {
        return $staff->filter(fn (User $member) => $member->isOwner() && $member->hasVerifiedEmail())->count();
    }
}
