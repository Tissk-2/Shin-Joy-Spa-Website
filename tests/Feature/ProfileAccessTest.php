<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/profile')->assertRedirect('/login');
    }

    public function test_unverified_users_are_redirected_to_the_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/profile')
            ->assertRedirect('/verify-email');
    }

    public function test_verified_users_receive_only_safe_shared_identity_fields(): void
    {
        $verifiedAt = now()->startOfSecond();
        $user = User::factory()->create([
            'name' => 'Ayu Lestari',
            'email' => 'ayu@example.com',
            'phone' => '+6281234567890',
            'email_verified_at' => $verifiedAt,
            'password' => Hash::make('secret-canary'),
            'remember_token' => 'remember-canary',
        ]);

        $response = $this->actingAs($user)->get('/profile');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile')
                ->has('auth.user', 5)
                ->where('auth.user', [
                    'id' => $user->id,
                    'name' => 'Ayu Lestari',
                    'email' => 'ayu@example.com',
                    'phone' => '+6281234567890',
                    'email_verified_at' => $verifiedAt->toISOString(),
                ]));

        $serialized = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret-canary', $serialized);
        $this->assertStringNotContainsString('remember-canary', $serialized);
    }
}
