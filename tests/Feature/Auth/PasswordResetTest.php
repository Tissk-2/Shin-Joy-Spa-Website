<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $response = $this->get('/reset-password/'.$notification->token.'?email='.urlencode($user->email));

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token_and_rotates_credentials(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => Hash::make('oldpass9'),
            'remember_token' => 'known-remember-token',
        ]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password9',
                'password_confirmation' => 'password9',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));
            $freshUser = $user->fresh();

            $this->assertTrue(Hash::check('password9', $freshUser->password));
            $this->assertFalse(Hash::check('oldpass9', $freshUser->password));
            $this->assertNotSame('known-remember-token', $freshUser->remember_token);
            $this->assertSame(60, strlen((string) $freshUser->remember_token));

            return true;
        });
    }

    public function test_invalid_reset_token_does_not_change_password_or_remember_token(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('oldpass9'),
            'remember_token' => 'known-remember-token',
        ]);

        $this->from('/reset-password/invalid-token')->post('/reset-password', [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'newpass9',
            'password_confirmation' => 'newpass9',
        ])->assertSessionHasErrors('email');

        $freshUser = $user->fresh();

        $this->assertTrue(Hash::check('oldpass9', $freshUser->password));
        $this->assertSame('known-remember-token', $freshUser->remember_token);
    }

    public function test_expired_reset_token_does_not_change_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => Hash::make('oldpass9')]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->travel(61)->minutes();

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'newpass9',
                'password_confirmation' => 'newpass9',
            ])->assertSessionHasErrors('email');

            $this->assertTrue(Hash::check('oldpass9', $user->fresh()->password));

            return true;
        });
    }

    public function test_reset_uses_the_shared_password_policy(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => Hash::make('oldpass9')]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'lettersonly',
                'password_confirmation' => 'lettersonly',
            ])->assertSessionHasErrors('password');

            $this->assertTrue(Hash::check('oldpass9', $user->fresh()->password));

            return true;
        });
    }
}
