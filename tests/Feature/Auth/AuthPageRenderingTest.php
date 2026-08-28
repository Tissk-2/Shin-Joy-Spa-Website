<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthPageRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_the_branded_inertia_component_with_required_props(): void
    {
        $this->withSession(['status' => 'Reset link sent.'])
            ->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->where('canResetPassword', true)
                ->where('status', 'Reset link sent.')
                ->missing('token')
                ->missing('email'));
    }

    public function test_registration_page_renders_without_sensitive_props(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Register')
                ->missing('token')
                ->missing('email')
                ->missing('password'));
    }

    public function test_forgot_password_page_renders_neutral_status(): void
    {
        $status = 'If an account exists for that email, a password reset link has been sent.';

        $this->withSession(['status' => $status])
            ->get('/forgot-password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/ForgotPassword')
                ->where('status', $status)
                ->missing('token'));
    }

    public function test_reset_password_page_renders_only_email_and_token_props(): void
    {
        $this->get('/reset-password/reset-token-value?email=ayu%40example.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/ResetPassword')
                ->where('email', 'ayu@example.com')
                ->where('token', 'reset-token-value')
                ->missing('password'));
    }

    public function test_verified_email_notice_requires_an_authenticated_user(): void
    {
        $this->get('/verify-email')->assertRedirect('/login');

        $this->actingAs(User::factory()->unverified()->create())
            ->withSession(['status' => 'verification-link-sent'])
            ->get('/verify-email')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/VerifyEmail')
                ->where('status', 'verification-link-sent')
                ->missing('token')
                ->missing('password'));
    }

    public function test_confirm_password_page_requires_an_authenticated_user(): void
    {
        $this->get('/confirm-password')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())
            ->get('/confirm-password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/ConfirmPassword')
                ->missing('token')
                ->missing('password'));
    }
}
