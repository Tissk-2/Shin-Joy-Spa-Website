<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_roles_have_customer_admin_and_owner_backed_values(): void
    {
        $this->assertSame(['customer', 'admin', 'owner'], array_column(UserRole::cases(), 'value'));
    }

    public function test_database_defaults_new_users_to_customer_without_factory_role_values(): void
    {
        $user = User::create([
            'name' => 'Ayu Lestari',
            'email' => 'ayu@example.com',
            'phone' => '+6281234567890',
            'password' => 'calmspa9',
        ])->refresh();

        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertFalse($user->isAdmin());
        $this->assertTrue(Schema::hasIndex('users', ['role']));
        $roleColumn = collect(Schema::getColumns('users'))->firstWhere('name', 'role');
        $this->assertFalse($roleColumn['nullable']);
    }

    public function test_factory_admin_state_does_not_change_the_customer_default(): void
    {
        $factory = User::factory();
        $admin = $factory->admin()->create();
        $customer = $factory->create();

        $this->assertSame(UserRole::Admin, $admin->refresh()->role);
        $this->assertTrue($admin->isAdmin());
        $this->assertSame(UserRole::Customer, $customer->refresh()->role);
        $this->assertFalse($customer->isAdmin());
    }

    public function test_database_rejects_unknown_roles(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $user->id)->update(['role' => 'superuser']);
    }

    public function test_mass_assignment_cannot_grant_or_revoke_a_role(): void
    {
        foreach ([['customer', 'admin'], ['customer', 'owner'], ['admin', 'customer'], ['owner', 'customer']] as [$initial, $requested]) {
            $user = User::factory()->create(['role' => $initial]);
            $user->fill(['name' => 'Updated Name', 'role' => $requested, 'can_manage_bookings' => true])->save();

            $this->assertSame($initial, $user->refresh()->role->value);
            $this->assertSame('Updated Name', $user->name);
            $this->assertFalse($user->can_manage_bookings);
        }

        $this->assertSame(['name', 'email', 'phone', 'password'], (new User)->getFillable());
    }

    public function test_role_migration_backfills_existing_users_and_rolls_back_its_index(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $migrationPath = database_path('migrations/2026_09_07_000000_add_role_to_users_table.php');
        $this->assertFileExists($migrationPath);
        $migration = require $migrationPath;
        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('users', 'role'));
            $this->assertFalse(Schema::hasIndex('users', ['role']));
            DB::table('users')->insert([
                'name' => 'Existing Customer',
                'email' => 'existing@example.com',
                'phone' => '+6281234567890',
                'password' => 'existing-password-hash',
            ]);
        } finally {
            $migration->up();
        }

        $user = User::where('email', 'existing@example.com')->firstOrFail();
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertFalse($user->isAdmin());
        $this->assertSame('existing-password-hash', $user->password);
        $this->assertTrue(Schema::hasIndex('users', ['role']));
    }

    public function test_users_table_stores_phone_and_email_verification_state(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'phone',
            'email_verified_at',
        ]));
    }

    public function test_users_are_email_verifiable_and_do_not_serialize_authentication_secrets(): void
    {
        $user = new User([
            'name' => 'Ayu Lestari',
            'email' => 'ayu@example.com',
            'phone' => '+6281234567890',
            'password' => 'secret',
        ]);
        $user->setRememberToken('remember-me');

        $this->assertInstanceOf(MustVerifyEmail::class, $user);
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }
}
