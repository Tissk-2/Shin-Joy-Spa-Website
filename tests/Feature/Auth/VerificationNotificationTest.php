<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmail;
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
            fn (VerifyEmail $notification): bool => $notification->locale === 'en',
        );
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
