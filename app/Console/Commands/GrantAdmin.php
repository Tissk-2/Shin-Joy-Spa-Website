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

final class GrantAdmin extends Command
{
    /** @var string */
    protected $signature = 'admin:grant {email} {--yes : Skip the confirmation prompt}';

    /** @var string */
    protected $description = 'Grant administrator access to an existing verified account';

    public function handle(): int
    {
        $email = CustomerIdentity::email((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            $this->error('A verified user with that email address was not found.');

            return self::FAILURE;
        }

        $this->line("Target account: {$user->name} <{$user->email}>");

        if (! $this->option('yes') && ! $this->confirm('Grant administrator access to this account?')) {
            $this->warn('No changes were made.');

            return self::SUCCESS;
        }

        try {
            $changed = (new StaffAccessLock)->run(function () use ($user, $email): bool {
                $target = StaffAuthority::confirmedTarget($user->getKey(), $email, true);
                if ($target->isAdmin()) {
                    return false;
                }
                StaffPermissionAudit::clearForRoleChange($target, 'admin:grant');
                $target->forceFill(['role' => UserRole::Admin])->save();

                return true;
            });
        } catch (StaffSchemaException|StaffTargetException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if (! $changed) {
            $this->info('This account already has administrator access.');

            return self::SUCCESS;
        }

        $this->info('Administrator access granted.');

        return self::SUCCESS;
    }
}
