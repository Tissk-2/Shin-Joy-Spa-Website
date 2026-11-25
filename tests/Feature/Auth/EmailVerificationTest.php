<?php

namespace Tests\Feature\Auth;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
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

    // Production break caught: a signed-in unverified customer must be sent to verification before booking can render.
    public function test_unverified_customer_is_redirected_to_verification_before_booking(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/booking?service=nezuko&mode=regular&duration=90')
            ->assertRedirect(route('verification.notice', absolute: false));
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
            ->assertRedirect(route('profile', absolute: false));
    }

    // Production break caught: resend endpoint must apply the same safe intended-path policy for already verified customers.
    public function test_verified_customer_cannot_use_a_hostile_intended_url_when_requesting_a_verification_resend(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['url.intended' => '//attacker.example/phishing'])
            ->post(route('verification.send'))
            ->assertRedirect(route('profile', absolute: false));
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

    // Flow review F1: the email link opened on a signed-out phone asked for a sign-in, then
    // showed the verify page again and left the account unverified.
    public function test_email_link_opened_while_signed_out_verifies_right_after_sign_in(): void
    {
        $user = User::factory()->unverified()->create(['password' => Hash::make('calmspa9')]);
        $link = $this->verificationUrlFor($user);

        $this->get($link)->assertRedirect(route('login', absolute: false));

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'calmspa9'])
            ->assertRedirect(substr($link, strlen('http://localhost')));

        $this->get($link)->assertRedirect(route('profile', absolute: false).'?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_another_accounts_email_link_still_goes_to_the_verify_page_after_sign_in(): void
    {
        $user = User::factory()->unverified()->create(['password' => Hash::make('calmspa9')]);
        $other = User::factory()->unverified()->create();

        $this->get($this->verificationUrlFor($other))->assertRedirect(route('login', absolute: false));

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'calmspa9'])
            ->assertRedirect(route('verification.notice', absolute: false));
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    // Flow review F13: opening another member page before verifying replaced the chosen treatment.
    public function test_the_chosen_treatment_survives_other_member_pages_until_verification(): void
    {
        $user = User::factory()->unverified()->create();
        $choice = '/booking?service=nezuko&mode=regular&duration=90';

        $this->actingAs($user)->get($choice)->assertRedirect(route('verification.notice', absolute: false));
        $this->actingAs($user)->get('/profile')->assertRedirect(route('verification.notice', absolute: false));
        $this->actingAs($user)->get('/bookings')->assertRedirect(route('verification.notice', absolute: false));
        $this->actingAs($user)->get('/booking')->assertRedirect(route('verification.notice', absolute: false));

        $this->actingAs($user)->get($this->verificationUrlFor($user))
            ->assertRedirect('/booking?duration=90&mode=regular&service=nezuko');
    }

    public function test_a_newer_treatment_choice_replaces_the_older_one_before_verification(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/booking?service=nezuko');
        $this->actingAs($user)->get('/booking?service=nezuko-vip');

        $this->actingAs($user)->get($this->verificationUrlFor($user))
            ->assertRedirect('/booking?service=nezuko-vip');
    }

    public function test_without_a_treatment_choice_the_last_member_page_is_remembered(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/bookings');
        $this->actingAs($user)->get('/profile');

        $this->actingAs($user)->get($this->verificationUrlFor($user))->assertRedirect('/profile');
    }

    // Flow review F6: after an email change, emails about open bookings still went to the old address.
    public function test_verifying_a_new_email_moves_open_bookings_to_it(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'ayu.new@example.com']);
        $open = [BookingStatus::Pending, BookingStatus::AwaitingPayment, BookingStatus::Confirmed];
        $closed = [BookingStatus::Cancelled, BookingStatus::Completed, BookingStatus::NoShow];
        $other = Booking::factory()->create(['status' => BookingStatus::Pending, 'customer_email' => 'someone@example.com']);
        foreach ([...$open, ...$closed] as $status) {
            Booking::factory()->for($user)->create(['status' => $status, 'customer_email' => 'ayu.old@example.com']);
        }

        $this->actingAs($user)->get($this->verificationUrlFor($user))->assertRedirect();

        foreach (Booking::query()->where('user_id', $user->id)->get() as $booking) {
            $this->assertSame(
                in_array($booking->status, $open, true) ? 'ayu.new@example.com' : 'ayu.old@example.com',
                $booking->customer_email,
                $booking->status->value,
            );
        }
        $this->assertSame('someone@example.com', $other->fresh()->customer_email);
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
