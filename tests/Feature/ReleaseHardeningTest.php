<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// Fixes from the final release review (E1f): M1, M2, M3, M7, M8 and M9.
class ReleaseHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_markdown_link_in_a_name_stays_text_in_account_emails(): void
    {
        $user = User::factory()->unverified()->create(['name' => '[Verify your account here](https://evil.example/x)']);

        foreach ([new VerifyEmail, new ResetPassword('test-token')] as $notification) {
            $mail = $notification->toMail($user);
            $html = (string) $mail->render();

            $this->assertStringContainsString('Verify your account here', $html, $notification::class);
            $this->assertStringNotContainsString('href="https://evil.example/x"', $html, $notification::class);
            // The genuine button link still renders whole, signature included.
            $this->assertStringContainsString('href="'.e($mail->actionUrl).'"', $html, $notification::class);
        }
    }

    public function test_password_confirmation_is_throttled(): void
    {
        $user = User::factory()->create();
        $statuses = [];

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $statuses[] = $this->actingAs($user)
                ->post(route('password.confirm.store'), ['password' => 'wrong-password'])
                ->status();
        }

        $this->assertSame([302, 302, 302, 302, 302, 429], $statuses);
    }

    public function test_changing_the_email_requires_the_current_password(): void
    {
        $user = User::factory()->create();
        $original = $user->email;

        $this->actingAs($user)->patch('/settings/profile', ['name' => $user->name, 'email' => 'moved@example.com'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($user)->patch('/settings/profile', [
            'name' => $user->name,
            'email' => 'moved@example.com',
            'current_password' => 'wrong-password',
        ])->assertSessionHasErrors('current_password');
        $this->assertSame($original, $user->fresh()->email);

        $this->actingAs($user)->patch('/settings/profile', [
            'name' => $user->name,
            'email' => 'moved@example.com',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();
        $this->assertSame('moved@example.com', $user->fresh()->email);
    }

    public function test_a_password_sent_with_an_unchanged_email_is_ignored_and_an_array_is_refused_cleanly(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/profile', [
            'name' => 'New Name',
            'email' => $user->email,
            'current_password' => 'stale-wrong-password',
        ])->assertSessionHasNoErrors();
        $this->assertSame('New Name', $user->fresh()->name);

        $this->actingAs($user)->patch('/settings/profile', [
            'name' => $user->name,
            'email' => 'moved@example.com',
            'current_password' => ['password'],
        ])->assertStatus(302)->assertSessionHasErrors('current_password');
    }

    public function test_confirmations_and_email_changes_share_one_password_guessing_budget(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'wrong-password'])->assertStatus(302);
        }

        $this->actingAs($user)->patch('/settings/profile', [
            'name' => $user->name,
            'email' => 'moved@example.com',
            'current_password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_an_unset_trusted_proxies_variable_still_trusts_no_proxy(): void
    {
        // phpunit.xml pins the variable to "", so remove it here to load the config as a
        // deployment without the line would. null would switch on Laravel's automatic trust
        // for *.on-forge.com hosts, the forged-Host bypass of finding M7.
        $saved = [getenv('TRUSTED_PROXIES'), $_ENV['TRUSTED_PROXIES'] ?? null, $_SERVER['TRUSTED_PROXIES'] ?? null];
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

        try {
            $proxies = (require config_path('trustedproxy.php'))['proxies'];
        } finally {
            if ($saved[0] !== false) {
                putenv('TRUSTED_PROXIES='.$saved[0]);
            }
            if ($saved[1] !== null) {
                $_ENV['TRUSTED_PROXIES'] = $saved[1];
            }
            if ($saved[2] !== null) {
                $_SERVER['TRUSTED_PROXIES'] = $saved[2];
            }
        }

        $this->assertSame([], $proxies);
    }

    public function test_the_proxy_settings_resolve_the_client_address_as_the_readme_says(): void
    {
        $resolve = function (mixed $proxies): ?string {
            config(['trustedproxy.proxies' => $proxies]);
            $request = Request::create('http://spa.example/', 'GET', server: [
                'REMOTE_ADDR' => '10.0.0.5',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 198.51.100.1',
            ]);
            (new TrustProxies)->handle($request, fn (): Response => new Response);

            return $request->ip();
        };

        $this->assertSame('10.0.0.5', $resolve([]), 'the empty default trusts no proxy');
        $this->assertSame('198.51.100.1', $resolve('REMOTE_ADDR'), 'REMOTE_ADDR keeps the address the proxy appended');
        $this->assertSame('203.0.113.9', $resolve('*'), 'why the README forbids *: the client chooses its own address');
    }

    public function test_wrong_passwords_on_the_profile_form_count_toward_the_settings_limit(): void
    {
        $user = User::factory()->create();
        $statuses = [];

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $statuses[] = $this->actingAs($user)->patch('/settings/profile', [
                'name' => $user->name,
                'email' => 'moved@example.com',
                'current_password' => 'wrong-password',
            ])->status();
        }

        $this->assertSame([302, 302, 302, 302, 302, 429], $statuses);
    }

    public function test_a_forged_forge_host_cannot_reset_the_login_limiter(): void
    {
        $control = User::factory()->create();
        $controlStatuses = [];
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $controlStatuses[] = $this->post('/login', ['email' => $control->email, 'password' => 'wrong-password'])->status();
        }
        $this->assertSame(429, end($controlStatuses), 'control: '.implode(',', $controlStatuses));

        $user = User::factory()->create();
        $statuses = [];
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $statuses[] = $this->withServerVariables(['HTTP_X_FORWARDED_FOR' => "203.0.113.{$attempt}"])
                ->post('http://x.on-forge.com/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->status();
        }
        $this->assertSame(429, end($statuses), 'forged: '.implode(',', $statuses));
    }

    public function test_emailed_links_use_https_when_the_app_url_does(): void
    {
        Notification::fake();
        config(['app.url' => 'https://spa.example']);
        $this->app->getProvider(AppServiceProvider::class)->boot();
        $user = User::factory()->create();

        $this->post('http://spa.example/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            return str_starts_with($notification->toMail($user)->actionUrl, 'https://spa.example/reset-password/');
        });
    }

    public function test_every_response_carries_the_security_headers(): void
    {
        foreach (['/up', '/a-page-that-does-not-exist'] as $path) {
            $response = $this->get($path);

            $response->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
            $response->assertHeader('X-Frame-Options', 'DENY');
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->assertHeaderMissing('Strict-Transport-Security');
        }

        $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
