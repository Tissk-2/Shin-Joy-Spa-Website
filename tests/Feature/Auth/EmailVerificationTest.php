<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_customer_can_render_the_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get(route('verification.notice'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/VerifyEmail'));
    }

    public function test_profile_requires_a_verified_email(): void
    {
        $unverifiedUser = User::factory()->unverified()->create();
        $verifiedUser = User::factory()->create();

        $this->actingAs($unverifiedUser)
            ->get(route('profile'))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($verifiedUser)
            ->get(route('profile'))
            ->assertOk();
    }

    public function test_valid_signed_url_verifies_email_and_dispatches_verified_event_once(): void
    {
        $user = User::factory()->unverified()->create();
        Event::fake([Verified::class]);

        $verificationUrl = $this->verificationUrlFor($user);

        $this->actingAs($user)
            ->get($verificationUrl)
            ->assertRedirect(route('profile', absolute: false).'?verified=1');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatchedTimes(Verified::class, 1);

        $this->travel(61)->seconds();

        $this->get($verificationUrl)
            ->assertRedirect(route('profile', absolute: false).'?verified=1');

        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_tampered_signed_url_is_rejected_without_verifying_email(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = $this->verificationUrlFor($user);
        $tamperedUrl = preg_replace('/signature=[^&]+/', 'signature=invalid', $verificationUrl);

        $this->actingAs($user)
            ->get((string) $tamperedUrl)
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_signed_url_is_rejected_without_verifying_email(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinute(),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        );

        $this->actingAs($user)
            ->get($verificationUrl)
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    private function verificationUrlFor(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        );
    }
}
