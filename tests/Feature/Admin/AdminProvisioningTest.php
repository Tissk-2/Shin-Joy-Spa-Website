<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisioning_commands_are_registered_without_password_arguments(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('admin:grant', $commands);
        $this->assertArrayHasKey('admin:revoke', $commands);
        $this->assertStringNotContainsString('password', $commands['admin:grant']->getDefinition()->getSynopsis());
        $this->assertStringNotContainsString('password', $commands['admin:revoke']->getDefinition()->getSynopsis());
    }

    public function test_grant_normalizes_email_and_never_changes_the_password(): void
    {
        $user = User::factory()->create([
            'name' => 'Ayu Lestari',
            'email' => 'owner@example.com',
        ]);
        $passwordHash = $user->password;

        $this->artisan('admin:grant', ['email' => ' OWNER@EXAMPLE.COM ', '--yes' => true])
            ->expectsOutputToContain('Ayu Lestari <owner@example.com>')
            ->doesntExpectOutputToContain($passwordHash)
            ->assertSuccessful();

        $this->assertSame(UserRole::Admin, $user->refresh()->role);
        $this->assertSame($passwordHash, $user->password);
    }

    public function test_grant_refuses_an_unverified_account(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'staff@example.com']);

        $this->artisan('admin:grant', ['email' => ' STAFF@EXAMPLE.COM ', '--yes' => true])
            ->expectsOutputToContain('A verified user with that email address was not found.')
            ->assertFailed();

        $this->assertSame(UserRole::Customer, $user->refresh()->role);
    }

    public function test_grant_fails_when_the_target_does_not_exist(): void
    {
        $this->artisan('admin:grant', ['email' => 'missing@example.com', '--yes' => true])
            ->expectsOutputToContain('A verified user with that email address was not found.')
            ->assertFailed();
    }

    public function test_grant_can_be_declined_after_showing_the_exact_target(): void
    {
        $user = User::factory()->create([
            'name' => 'Bima Pratama',
            'email' => 'bima@example.com',
        ]);

        $this->artisan('admin:grant', ['email' => ' BIMA@EXAMPLE.COM '])
            ->expectsOutputToContain('Bima Pratama <bima@example.com>')
            ->expectsConfirmation('Grant administrator access to this account?', 'no')
            ->expectsOutputToContain('No changes were made.')
            ->assertSuccessful();

        $this->assertSame(UserRole::Customer, $user->refresh()->role);
    }

    public function test_repeated_grant_is_a_confirmed_successful_no_op(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Existing Admin',
            'email' => 'admin@example.com',
        ]);
        $passwordHash = $admin->password;

        $this->artisan('admin:grant', ['email' => 'ADMIN@EXAMPLE.COM'])
            ->expectsOutputToContain('Existing Admin <admin@example.com>')
            ->expectsConfirmation('Grant administrator access to this account?', 'yes')
            ->expectsOutputToContain('This account already has administrator access.')
            ->assertSuccessful();

        $this->assertSame(UserRole::Admin, $admin->refresh()->role);
        $this->assertSame($passwordHash, $admin->password);
    }

    public function test_revoke_normalizes_email_preserves_password_and_succeeds_when_another_admin_exists(): void
    {
        User::factory()->admin()->create();
        $admin = User::factory()->admin()->create([
            'name' => 'Rani Putri',
            'email' => 'rani@example.com',
        ]);
        $passwordHash = $admin->password;

        $this->artisan('admin:revoke', ['email' => ' RANI@EXAMPLE.COM ', '--yes' => true])
            ->expectsOutputToContain('Rani Putri <rani@example.com>')
            ->doesntExpectOutputToContain($passwordHash)
            ->assertSuccessful();

        $this->assertSame(UserRole::Customer, $admin->refresh()->role);
        $this->assertSame($passwordHash, $admin->password);
    }

    public function test_revoke_can_be_declined_after_showing_the_exact_target(): void
    {
        User::factory()->admin()->create();
        $admin = User::factory()->admin()->create([
            'name' => 'Dewi Staff',
            'email' => 'dewi@example.com',
        ]);

        $this->artisan('admin:revoke', ['email' => ' DEWI@EXAMPLE.COM '])
            ->expectsOutputToContain('Dewi Staff <dewi@example.com>')
            ->expectsConfirmation('Revoke administrator access from this account?', 'no')
            ->expectsOutputToContain('No changes were made.')
            ->assertSuccessful();

        $this->assertSame(UserRole::Admin, $admin->refresh()->role);
    }

    public function test_repeated_revoke_is_a_confirmed_successful_no_op(): void
    {
        $customer = User::factory()->create([
            'name' => 'Customer Account',
            'email' => 'customer@example.com',
        ]);

        $this->artisan('admin:revoke', ['email' => 'CUSTOMER@EXAMPLE.COM'])
            ->expectsOutputToContain('Customer Account <customer@example.com>')
            ->expectsConfirmation('Revoke administrator access from this account?', 'yes')
            ->expectsOutputToContain('This account does not have administrator access.')
            ->assertSuccessful();

        $this->assertSame(UserRole::Customer, $customer->refresh()->role);
    }

    public function test_revoke_fails_when_the_target_does_not_exist(): void
    {
        $this->artisan('admin:revoke', ['email' => 'missing@example.com', '--yes' => true])
            ->expectsOutputToContain('A user with that email address was not found.')
            ->assertFailed();
    }

    public function test_revoke_refuses_to_remove_the_final_administrator(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'only-admin@example.com']);

        $this->artisan('admin:revoke', ['email' => $admin->email, '--yes' => true])
            ->expectsOutputToContain('The final administrator cannot be revoked.')
            ->assertFailed();

        $this->assertSame(UserRole::Admin, $admin->refresh()->role);
    }

    public function test_revoke_retains_its_concurrency_safety_contract(): void
    {
        User::factory()->admin()->create();
        $target = User::factory()->admin()->create(['email' => 'locked@example.com']);
        $baselineTransactionLevel = DB::transactionLevel();
        $transactionLevelAtRoleUpdate = null;

        User::updating(function (User $user) use (&$transactionLevelAtRoleUpdate): void {
            if ($user->isDirty('role')) {
                $transactionLevelAtRoleUpdate = DB::transactionLevel();
            }
        });

        DB::enableQueryLog();

        $this->artisan('admin:revoke', ['email' => $target->email, '--yes' => true])
            ->assertSuccessful();

        $queries = array_column(DB::getQueryLog(), 'query');
        $administratorQueryIndex = $this->firstQueryIndexContaining(
            $queries,
            'select "id" from "users" where "role" = ? order by "id" asc',
        );
        $targetRereadQueryIndex = $this->firstQueryIndexContaining(
            $queries,
            'select * from "users" where "users"."id" = ? limit 1',
        );
        $roleUpdateQueryIndex = $this->firstQueryIndexContaining(
            $queries,
            'update "users" set "role" = ?, "updated_at" = ?',
        );

        $this->assertSame($baselineTransactionLevel + 1, $transactionLevelAtRoleUpdate);
        $this->assertLessThan($targetRereadQueryIndex, $administratorQueryIndex);
        $this->assertLessThan($roleUpdateQueryIndex, $targetRereadQueryIndex);

        $source = file_get_contents(app_path('Console/Commands/RevokeAdmin.php'));

        $this->assertIsString($source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, '->lockForUpdate()'));
        $this->assertStringContainsString('}, 3);', $source);
        $this->assertStringContainsString('if ($adminIds->count() <= 1)', $source);
    }

    /**
     * @param  list<string>  $queries
     */
    private function firstQueryIndexContaining(array $queries, string $fragment): int
    {
        foreach ($queries as $index => $query) {
            if (str_contains($query, $fragment)) {
                return $index;
            }
        }

        $this->fail("Expected query fragment was not executed: {$fragment}");
    }
}
