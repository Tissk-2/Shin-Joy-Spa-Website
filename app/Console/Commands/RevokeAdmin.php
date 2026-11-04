<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Exceptions\FinalAdministratorException;
use App\Models\User;
use App\Support\CustomerIdentity;
use App\Support\Staff\StaffAccessLock;
use App\Support\Staff\StaffAuthority;
use App\Support\Staff\StaffPermissionAudit;
use App\Support\Staff\StaffSchemaException;
use App\Support\Staff\StaffTargetException;
use Illuminate\Console\Command;

final class RevokeAdmin extends Command
{
    /** @var string */
    protected $signature = 'admin:revoke {email} {--yes : Skip the confirmation prompt}';

    /** @var string */
    protected $description = 'Revoke administrator access without removing the final administrator';

    public function handle(): int
    {
        $email = CustomerIdentity::email((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error('A user with that email address was not found.');

            return self::FAILURE;
        }

        $this->line("Target account: {$user->name} <{$user->email}>");

        if (! $this->option('yes') && ! $this->confirm('Revoke administrator access from this account?')) {
            $this->warn('No changes were made.');

            return self::SUCCESS;
        }

        try {
            $changed = (new StaffAccessLock)->run(function () use ($user, $email): bool {
                $lockedTarget = StaffAuthority::confirmedTarget($user->getKey(), $email, false);
                if ($lockedTarget->isOwner()) {
                    throw new StaffTargetException('Owner access cannot be revoked by admin:revoke.');
                }

                if (! $lockedTarget->isAdmin()) {
                    return false;
                }

                StaffAuthority::protectFinalAdmin($lockedTarget);
                StaffPermissionAudit::clearForRoleChange($lockedTarget, 'admin:revoke');

                $lockedTarget->forceFill(['role' => UserRole::Customer])->save();

                return true;
            });
        } catch (StaffSchemaException|StaffTargetException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (FinalAdministratorException) {
            $this->error('The final administrator cannot be revoked.');

            return self::FAILURE;
        }

        if (! $changed) {
            $this->info('This account does not have administrator access.');

            return self::SUCCESS;
        }

        $this->info('Administrator access revoked.');

        return self::SUCCESS;
    }
}
