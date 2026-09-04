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

    // Production break caught: signed verification must complete the preserved local booking journey, not discard it.
    public function test_signed_verification_redirects_to_the_safe_configured_booking_intent(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->withSession(['url.intended' => '/booking?service=nezuko&mode=regular&duration=90'])
            ->get($this->verificationUrlFor($user))
            ->assertRedirect('/booking?service=nezuko&mode=regular&duration=90');
    }

    // Production break caught: verification-only endpoints must not provide an open-redirect bypass for hostile intended URLs.
    public function test_verified_customer_cannot_use_a_hostile_intended_url_at_the_verification_prompt(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['url.intended' => 'https://attacker.example/phishing'])
            ->get(route('verification.notice'))
            ->assertRedirect(route('dashboard', absolute: false));
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
