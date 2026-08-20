<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Fortify;
use Tests\TestCase;

class VerificationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resend_uses_the_session_locale_and_a_sixty_minute_signed_url(): void
    {
        Carbon::setTestNow('2026-08-20 10:00:00');
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->withSession(['locale' => 'id'])
            ->post(route('verification.send'))
            ->assertSessionHas('status', Fortify::VERIFICATION_LINK_SENT);

        Notification::assertSentTo(
            $user,
            VerifyEmail::class,
            function (VerifyEmail $notification) use ($user): bool {
                $message = $notification->toMail($user);

                $this->assertInstanceOf(MailMessage::class, $message);
                $this->assertSame('id', $notification->locale);
                $this->assertSame(
                    now()->addMinutes(60)->timestamp,
                    (int) $this->queryParameters($message->actionUrl)['expires'],
                );

                return URL::hasValidSignature(Request::create($message->actionUrl));
            },
        );
    }

    public function test_invalid_session_locale_falls_back_safely(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->withSession(['locale' => 'fr'])
            ->post(route('verification.send'));

        Notification::assertSentTo(
            $user,
            VerifyEmail::class,
            fn (VerifyEmail $notification): bool => $notification->locale === 'id',
        );
    }

    public function test_missing_session_locale_uses_the_indonesian_application_default(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('verification.send'));

        Notification::assertSentTo(
            $user,
            VerifyEmail::class,
            fn (VerifyEmail $notification): bool => $notification->locale === 'id',
        );
    }

    public function test_hostile_request_host_cannot_poison_the_signed_verification_link(): void
    {
        $user = User::factory()->unverified()->create();
        $hostileHost = 'attacker.example.test';
        $dispatcher = new CapturingVerificationNotificationDispatcher;
        $this->app->instance(NotificationDispatcher::class, $dispatcher);

        $this->actingAs($user)
            ->withHeader('Host', $hostileHost)
            ->post("http://{$hostileHost}".route('verification.send', absolute: false))
            ->assertRedirect();

        $this->assertSame(VerifyEmail::class, $dispatcher->notificationClass);
        $this->assertNotNull($dispatcher->actionUrl);

        $actionUrl = $dispatcher->actionUrl;
        $configuredOrigin = parse_url((string) config('app.url'));
        $actionOrigin = parse_url($actionUrl);

        $this->assertSame($configuredOrigin['scheme'], $actionOrigin['scheme']);
        $this->assertSame($configuredOrigin['host'], $actionOrigin['host']);
        $this->assertSame($configuredOrigin['port'] ?? null, $actionOrigin['port'] ?? null);
        $this->assertStringNotContainsString($hostileHost, $actionUrl);

        $this->travel(61)->seconds();

        $this->get($actionUrl)
            ->assertRedirect(route('profile', absolute: false).'?verified=1');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_resend_is_limited_to_once_per_minute(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect();

        $this->post(route('verification.send'))
            ->assertTooManyRequests();

        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
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

final class CapturingVerificationNotificationDispatcher implements NotificationDispatcher
{
    public ?string $actionUrl = null;

    public ?string $notificationClass = null;

    public function send($notifiables, $notification): void
    {
        if (! $notifiables instanceof User || ! $notification instanceof VerifyEmail) {
            return;
        }

        $this->notificationClass = $notification::class;
        $this->actionUrl = $notification->toMail($notifiables)->actionUrl;
    }

    public function sendNow($notifiables, $notification, ?array $channels = null): void
    {
        $this->send($notifiables, $notification);
    }
}
