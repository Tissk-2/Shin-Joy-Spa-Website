<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'new-password9',
                'password_confirmation' => 'new-password9',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/password');

        $this->assertTrue(Hash::check('new-password9', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password9',
                'password_confirmation' => 'new-password9',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/settings/password');
    }

    public function test_letters_only_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'abcdefgh',
                'password_confirmation' => 'abcdefgh',
            ])
            ->assertSessionHasErrors('password')
            ->assertRedirect('/settings/password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_digits_only_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => '12345678',
                'password_confirmation' => '12345678',
            ])
            ->assertSessionHasErrors('password')
            ->assertRedirect('/settings/password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_beyond_the_72_byte_boundary_is_rejected(): void
    {
        $user = User::factory()->create();
        $password = str_repeat('a', 72).'1';
        $this->assertSame(73, strlen($password));

        $this->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => $password,
                'password_confirmation' => $password,
            ])
            ->assertSessionHasErrors(['password' => trans('validation.custom.password.bytes')])
            ->assertRedirect('/settings/password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_changes_are_rate_limited_after_five_attempts(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user)
                ->putJson('/settings/password', [
                    'current_password' => 'wrong-password',
                    'password' => 'new-password9',
                    'password_confirmation' => 'new-password9',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('current_password');
        }

        $this->actingAs($user)
            ->putJson('/settings/password', [
                'current_password' => 'password',
                'password' => 'new-password9',
                'password_confirmation' => 'new-password9',
            ])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
