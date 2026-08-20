<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_and_unknown_emails_receive_the_same_html_response(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $existing = $this->from('/forgot-password')->post('/forgot-password', [
            'email' => $user->email,
        ]);
        $unknown = $this->from('/forgot-password')->post('/forgot-password', [
            'email' => 'missing@example.com',
        ]);

        $existing->assertRedirect('/forgot-password')
            ->assertSessionHas('status', __('passwords.sent'))
            ->assertSessionHasNoErrors();
        $unknown->assertRedirect('/forgot-password')
            ->assertSessionHas('status', __('passwords.sent'))
            ->assertSessionHasNoErrors();

        $this->assertSame($existing->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame(
            $existing->getSession()->get('status'),
            $unknown->getSession()->get('status'),
        );
    }

    public function test_existing_and_unknown_emails_receive_the_same_json_response(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $existing = $this->postJson('/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/forgot-password', ['email' => 'missing@example.com']);

        $existing->assertOk()->assertExactJson(['message' => __('passwords.sent')]);
        $unknown->assertOk()->assertExactJson(['message' => __('passwords.sent')]);

        $this->assertSame($existing->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($existing->json(), $unknown->json());
    }

    public function test_notification_is_sent_only_for_an_existing_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);
        $this->post('/forgot-password', ['email' => 'missing@example.com']);

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        Notification::assertCount(1);
    }

    public function test_broker_throttle_does_not_send_duplicate_mail_or_change_the_visible_response(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $first = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $second = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);

        $first->assertSessionHas('status', __('passwords.sent'));
        $second->assertSessionHas('status', __('passwords.sent'));
        $this->assertSame($first->getStatusCode(), $second->getStatusCode());
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_password_reset_link_requests_are_rate_limited_by_request_origin(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $attempt) {
            $this->post('/forgot-password', [
                'email' => "missing{$attempt}@example.com",
            ])->assertRedirect();
        }

        $this->post('/forgot-password', ['email' => 'missing6@example.com'])
            ->assertTooManyRequests();
    }

    public function test_reset_notification_uses_locale_expiry_and_the_trusted_app_origin(): void
    {
        Carbon::setTestNow('2026-08-20 10:00:00');
        Notification::fake();
        $user = User::factory()->create();
        $hostileHost = 'attacker.example.test';
        app()->setLocale('id');
        Lang::addLines([
            'mail.reset.expiry' => 'This reset link expires in :minutes minutes.',
        ], 'id');

        $this->withHeader('Host', $hostileHost)
            ->post("http://{$hostileHost}/forgot-password", ['email' => $user->email])
            ->assertRedirect();

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use ($user, $hostileHost): bool {
                $message = $notification->toMail($user);
                $configuredOrigin = parse_url((string) config('app.url'));
                $actionOrigin = parse_url((string) $message->actionUrl);

                $this->assertInstanceOf(MailMessage::class, $message);
                $this->assertSame('id', $notification->locale);
                $this->assertSame($configuredOrigin['scheme'], $actionOrigin['scheme']);
                $this->assertSame($configuredOrigin['host'], $actionOrigin['host']);
                $this->assertSame($configuredOrigin['port'] ?? null, $actionOrigin['port'] ?? null);
                $this->assertStringNotContainsString($hostileHost, (string) $message->actionUrl);
                $this->assertSame($user->email, $this->queryParameters((string) $message->actionUrl)['email']);
                $this->assertStringContainsString('/reset-password/'.$notification->token, (string) $message->actionUrl);
                $this->assertStringContainsString('60', implode(' ', [
                    ...$message->introLines,
                    ...$message->outroLines,
                ]));

                return true;
            },
        );
    }

    /**
     * @return array<string, string>
     */
    private function queryParameters(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);

        return $parameters;
    }
}
