<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roleChangeProvider')]
    public function test_profile_update_cannot_change_the_role(string $initial, string $requested): void
    {
        $user = User::factory()->create(['role' => $initial]);

        $this->actingAs($user)->patch('/settings/profile', [
            'name' => 'Updated Customer',
            'email' => $user->email,
            'role' => $requested,
        ])->assertSessionHasNoErrors()->assertRedirect('/settings/profile');

        $this->assertSame($initial, $user->refresh()->role->value);
        $this->assertSame('Updated Customer', $user->name);
    }

    /** @return array<string, array{string, string}> */
    public static function roleChangeProvider(): array
    {
        return [
            'customer cannot grant admin' => ['customer', 'admin'],
            'admin cannot revoke through profile' => ['admin', 'customer'],
        ];
    }

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/settings/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/settings/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_final_administrator_cannot_delete_their_account(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from('/settings/profile')
            ->delete('/settings/profile', [
                'password' => 'password',
            ])
            ->assertRedirect('/settings/profile')
            ->assertSessionHasErrors('password');

        $this->assertAuthenticatedAs($admin);
        $this->assertSame(UserRole::Admin, $admin->fresh()?->role);
    }

    public function test_account_deletion_uses_the_fresh_persisted_role_when_the_session_user_is_stale(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        User::query()->whereKey($user->id)->update(['role' => UserRole::Admin->value]);

        $this->assertSame(UserRole::Customer, $user->role);

        $this->from('/settings/profile')
            ->delete('/settings/profile', [
                'password' => 'password',
            ])
            ->assertRedirect('/settings/profile')
            ->assertSessionHasErrors('password');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(UserRole::Admin, $user->fresh()?->role);
    }

    public function test_administrator_can_delete_their_account_when_another_administrator_remains(): void
    {
        $admin = User::factory()->admin()->create();
        $remainingAdmin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->delete('/settings/profile', [
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($admin->fresh());
        $this->assertSame(UserRole::Admin, $remainingAdmin->fresh()?->role);
    }

    public function test_administrator_account_deletion_acquires_staff_guard_before_fresh_target_in_a_transaction(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();
        $connection = DB::connection();
        $baselineTransactionLevel = DB::transactionLevel();
        $transactionLevelAtDeletion = null;
        $originalGrammar = $connection->getQueryGrammar();
        $recordingGrammar = new class($connection) extends SQLiteGrammar
        {
            /** @var list<array{from: string|null, lock: string|bool|null}> */
            public array $compiledSelects = [];

            public function compileSelect(Builder $query)
            {
                $this->compiledSelects[] = [
                    'from' => $query->from,
                    'lock' => $query->lock,
                ];

                return parent::compileSelect($query);
            }
        };

        User::deleting(function (User $deleting) use ($admin, &$transactionLevelAtDeletion): void {
            if ($deleting->is($admin)) {
                $transactionLevelAtDeletion = DB::transactionLevel();
            }
        });
        $connection->setQueryGrammar($recordingGrammar);
        DB::enableQueryLog();

        try {
            $this->actingAs($admin)->delete('/settings/profile', [
                'password' => 'password',
            ])->assertRedirect('/');
        } finally {
            $connection->setQueryGrammar($originalGrammar);
            User::flushEventListeners();
        }

        $lockedUserSelects = array_values(array_filter(
            $recordingGrammar->compiledSelects,
            fn (array $select): bool => $select['from'] === 'users' && $select['lock'] === true,
        ));

        $this->assertSame($baselineTransactionLevel + 1, $transactionLevelAtDeletion);
        $this->assertCount(2, $lockedUserSelects);
        $queries = array_column(DB::getQueryLog(), 'query');
        $guard = array_find_key($queries, fn ($query) => str_starts_with($query, 'update "staff_access_guards"'));
        $target = array_find_key($queries, fn ($query) => str_starts_with($query, 'select * from "users" where "users"."id"'));
        $deletion = array_find_key($queries, fn ($query) => str_starts_with($query, 'delete from "users"'));
        $this->assertNotNull($guard);
        $this->assertNotNull($target);
        $this->assertNotNull($deletion);
        $this->assertLessThan($target, $guard);
        $this->assertLessThan($deletion, $target);
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/profile')
            ->delete('/settings/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->fresh());
    }
}
