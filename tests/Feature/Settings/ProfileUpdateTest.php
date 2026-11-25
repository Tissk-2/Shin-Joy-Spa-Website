<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
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

    public function test_the_old_settings_pages_lead_to_the_account_page()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings')->assertRedirect('/profile');
        $this->actingAs($user)->get('/settings/profile')->assertRedirect('/profile');
        $this->actingAs($user)->get('/settings/password')->assertRedirect('/profile#password');
        $this->actingAs($user)->get('/settings/appearance')->assertRedirect('/profile');
        $this->actingAs($user)->get('/profile')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Profile'));
    }

    public function test_profile_information_can_be_updated()
    {
        Notification::fake();
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'current_password' => 'password',
            ]);

        // A new address gets its own link at once, and the account waits on the verify page.
        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/verify-email')
            ->assertSessionHas('status', 'verification-link-sent');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_an_unverified_customer_can_fix_a_mistyped_email_and_gets_a_new_link()
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'ayu@gmial.com']);

        $this->actingAs($user)->get('/profile')->assertRedirect('/verify-email');
        $this->actingAs($user)->patch('/settings/profile', [
            'name' => $user->name,
            'email' => 'ayu@gmail.com',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors()
            ->assertRedirect('/verify-email')
            ->assertSessionHas('status', 'verification-link-sent');

        $this->assertSame('ayu@gmail.com', $user->refresh()->email);
        Notification::assertSentTo(
            $user,
            VerifyEmail::class,
            fn (VerifyEmail $notification, array $channels, User $notifiable): bool => $notifiable->email === 'ayu@gmail.com',
        );
    }

    public function test_a_name_only_change_sends_no_verification_link()
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/profile', [
            'name' => 'Renamed',
            'email' => $user->email,
        ])->assertSessionHasNoErrors()->assertRedirect('/settings/profile');

        Notification::assertNothingSent();
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

    // Flow review F10: the WhatsApp number could only be set at sign-up.
    public function test_the_whatsapp_number_can_be_changed_and_is_checked_like_at_sign_up(): void
    {
        $user = User::factory()->create(['phone' => '+6281111111111']);

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => $user->name, 'email' => $user->email, 'phone' => ' +62 812-3456-7890 '])
            ->assertSessionHasNoErrors();
        $this->assertSame('+6281234567890', $user->fresh()->phone);

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => $user->name, 'email' => $user->email, 'phone' => '12-34'])
            ->assertSessionHasErrors('phone');
        $this->assertSame('+6281234567890', $user->fresh()->phone);

        // Leaving the field out keeps the number; it is required at sign-up, so it cannot be cleared.
        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => 'Ayu', 'email' => $user->email])
            ->assertSessionHasNoErrors();
        $this->assertSame('+6281234567890', $user->fresh()->phone);

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => 'Ayu', 'email' => $user->email, 'phone' => ' '])
            ->assertSessionHasErrors('phone');
        $this->assertSame('+6281234567890', $user->fresh()->phone);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/settings/profile', [
                'password' => 'password',
            ]);

        // Flow review F11: the home page says the account was deleted.
        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/')
            ->assertSessionHas('status', 'account-deleted');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_a_deleted_users_reset_token_cannot_target_a_replacement_account(): void
    {
        $user = User::factory()->create();
        $email = $user->email;
        $token = Password::broker()->createToken($user);

        $this->actingAs($user)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $replacement = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('replacement9'),
        ]);

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token,
            'email' => $email,
            'password' => 'attacker9',
            'password_confirmation' => 'attacker9',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('replacement9', $replacement->fresh()->password));
    }

    // deleteToken() runs inside the StaffAccessLock transaction, so a rejected
    // deletion or email change rolls the token delete back and the reset stays usable.
    public function test_a_final_administrator_deletion_keeps_the_reset_token(): void
    {
        $admin = User::factory()->admin()->create();
        Password::broker()->createToken($admin);

        $this->actingAs($admin)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $admin->email]);
    }

    public function test_a_final_owner_email_change_keeps_the_reset_token(): void
    {
        $owner = User::factory()->owner()->create();
        Password::broker()->createToken($owner);

        $this->actingAs($owner)
            ->patch('/settings/profile', ['name' => $owner->name, 'email' => 'moved-owner@example.com', 'current_password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $owner->email]);
        $this->assertNotSame('moved-owner@example.com', $owner->fresh()->email);
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

    public function test_changing_the_email_deletes_the_reset_token_for_the_old_address(): void
    {
        $user = User::factory()->create();
        $oldEmail = $user->email;
        Password::broker()->createToken($user);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $oldEmail]);

        $this->actingAs($user)
            ->patch('/settings/profile', [
                'name' => $user->name,
                'email' => 'moved@example.com',
                'current_password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
    }

    public function test_the_old_reset_token_cannot_target_an_account_that_reuses_the_old_email(): void
    {
        $user = User::factory()->create();
        $oldEmail = $user->email;
        $token = Password::broker()->createToken($user);

        $this->actingAs($user)
            ->patch('/settings/profile', [
                'name' => $user->name,
                'email' => 'moved@example.com',
                'current_password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $this->post(route('logout'));

        $replacement = User::factory()->create([
            'email' => $oldEmail,
            'password' => Hash::make('replacement9'),
        ]);

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token,
            'email' => $oldEmail,
            'password' => 'attacker9',
            'password_confirmation' => 'attacker9',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('replacement9', $replacement->fresh()->password));
    }

    public function test_deleting_the_account_deletes_the_reset_token(): void
    {
        $user = User::factory()->create();
        Password::broker()->createToken($user);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

        $this->actingAs($user)
            ->delete('/settings/profile', [
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_a_rejected_deletion_keeps_the_reset_token(): void
    {
        $user = User::factory()->create();
        Password::broker()->createToken($user);

        $this->actingAs($user)
            ->from('/settings/profile')
            ->delete('/settings/profile', [
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }
}
