<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get(route('login'))->assertOk();
    }

    public function test_unverified_users_can_log_in_with_a_normalized_email_and_are_sent_to_verification(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'ayu@example.com',
            'password' => Hash::make('calmspa9'),
        ]);

        $response = $this->post(route('login.store'), [
            'email' => ' AYU@EXAMPLE.COM ',
            'password' => 'calmspa9',
        ]);

        $response->assertRedirect(route('verification.notice', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    // Production break caught: an authenticated-but-unverified customer must retain a safe configured booking handoff for signed verification.
    public function test_unverified_login_retains_the_safe_configured_booking_intent(): void
    {
        $user = User::factory()->unverified()->create([
            'password' => Hash::make('calmspa9'),
        ]);

        $this->get('/booking?service=nezuko&mode=regular&duration=90')
            ->assertRedirect(route('login', absolute: false));

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'calmspa9',
        ])->assertRedirect(route('verification.notice', absolute: false));

        $this->assertSame('http://localhost/booking?duration=90&mode=regular&service=nezuko', session('url.intended'));
    }

    public function test_unknown_email_and_incorrect_password_return_the_same_generic_error(): void
    {
        $user = User::factory()->create([
            'email' => 'ayu@example.com',
            'password' => Hash::make('calmspa9'),
        ]);

        $unknownResponse = $this->from(route('login'))->post(route('login.store'), [
            'email' => 'missing@example.com',
            'password' => 'calmspa9',
        ]);
        $unknownError = session('errors')->get('email');

        $incorrectResponse = $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ]);
        $incorrectError = session('errors')->get('email');

        $unknownResponse->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $incorrectResponse->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertSame($unknownError, $incorrectError);
        $this->assertGuest();
    }

    public function test_verified_users_are_redirected_to_the_internal_page_they_requested(): void
    {
        $user = User::factory()->create([
            'email' => 'ayu@example.com',
            'password' => Hash::make('calmspa9'),
        ]);

        $this->get('/profile')->assertRedirect(route('login', absolute: false));

        $response = $this->post(route('login.store'), [
            'email' => ' AYU@EXAMPLE.COM ',
            'password' => 'calmspa9',
        ]);

        $response->assertRedirect('/profile');
        $this->assertAuthenticatedAs($user);
    }

    public function test_external_previous_and_intended_urls_are_never_used_after_login(): void
    {
        $user = User::factory()->create([
            'email' => 'ayu@example.com',
            'password' => Hash::make('calmspa9'),
        ]);

        $response = $this->withSession([
            '_previous.url' => 'https://attacker.example/phishing',
            'url.intended' => 'https://attacker.example/phishing',
        ])->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'calmspa9',
        ]);

        $response->assertRedirect(route('profile', absolute: false));
        $this->assertFalse(str_starts_with((string) $response->headers->get('Location'), 'https://attacker.example'));
    }

    // Production break caught: protocol-relative intended paths would turn an internal redirect into an open redirect.
    public function test_protocol_relative_intended_url_is_discarded_after_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('calmspa9')]);

        $this->withSession(['url.intended' => '//attacker.example/phishing'])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'calmspa9'])
            ->assertRedirect(route('profile', absolute: false));
    }

    // Production break caught: a same-origin absolute URL must not normalize into a protocol-relative redirect target.
    public function test_same_origin_absolute_intended_url_with_a_double_slash_path_is_discarded_after_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('calmspa9')]);

        $this->withSession(['url.intended' => 'http://localhost//attacker.example/phishing'])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'calmspa9'])
            ->assertRedirect(route('profile', absolute: false));
    }

    // Production break caught: control bytes, including percent-encoded ones, must never reach a Location header.
    public function test_control_character_intended_urls_are_discarded_after_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('calmspa9')]);
        $encodedControlUser = User::factory()->create(['password' => Hash::make('calmspa9')]);

        $this->withSession(['url.intended' => "/booking?note=ok\r\nLocation:%20https://attacker.example"])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'calmspa9'])
            ->assertRedirect(route('profile', absolute: false));

        $this->post(route('logout'));

        $this->withSession(['url.intended' => '/booking?note=%0d%0aLocation%3A%20https%3A%2F%2Fattacker.example'])
            ->post(route('login.store'), ['email' => $encodedControlUser->email, 'password' => 'calmspa9'])
            ->assertRedirect(route('profile', absolute: false));
    }

    public function test_remember_me_login_creates_the_recaller_cookie(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('calmspa9'),
        ]);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'calmspa9',
            'remember' => true,
        ]);

        $response->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_stored_password_beyond_the_72_byte_limit_still_logs_in(): void
    {
        $long = str_repeat('a', 80).'1';
        $this->assertSame(81, strlen($long));

        $user = User::factory()->create(['password' => Hash::make($long)]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => $long]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_logout_and_their_session_is_invalidated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession([
                '_token' => 'known-csrf-token',
                'private-session-value' => 'must-be-cleared',
            ])
            ->post(route('logout'));

        $this->assertGuest();
        $response->assertRedirect(route('home', absolute: false));
        $response->assertSessionMissing('private-session-value');
        $this->assertNotSame('known-csrf-token', session()->token());
    }

    public function test_array_login_input_returns_field_errors_instead_of_a_server_error(): void
    {
        $this->post(route('login.store'), [
            'email' => ['x'],
            'password' => ['x'],
        ])->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    }
}
