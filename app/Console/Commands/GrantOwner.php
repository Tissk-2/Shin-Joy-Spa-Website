<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\CustomerIdentity;
use App\Support\Staff\StaffAccessLock;
use App\Support\Staff\StaffAuthority;
use App\Support\Staff\StaffPermissionAudit;
use App\Support\Staff\StaffSchemaException;
use App\Support\Staff\StaffTargetException;
use Illuminate\Console\Command;

final class GrantOwner extends Command
{
    protected $signature = 'owner:grant {email} {--yes : Skip the confirmation prompt}';

    protected $description = 'Grant owner access to an existing verified account';

    public function handle(): int
    {
        $email = CustomerIdentity::email((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();
        if (! $user || ! $user->hasVerifiedEmail()) {
            $this->error('A verified user with that email address was not found.');

            return self::FAILURE;
        }
        $this->line("Target account: {$user->name} <{$user->email}>");
        if (! $this->option('yes') && ! $this->confirm('Grant owner access to this account?')) {
            $this->warn('No changes were made.');

            return self::SUCCESS;
        }
        try {
            $changed = (new StaffAccessLock(requireSchema: true))->run(function () use ($user, $email): bool {
                $target = StaffAuthority::confirmedTarget($user->getKey(), $email, true);
                if ($target->isOwner()) {
                    return false;
                }
                StaffPermissionAudit::clearForRoleChange($target, 'owner:grant');
                $target->forceFill(['role' => UserRole::Owner])->save();

                return true;
            });
        } catch (StaffSchemaException|StaffTargetException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info($changed ? 'Owner access granted.' : 'This account already has owner access.');

        return self::SUCCESS;
    }
}
