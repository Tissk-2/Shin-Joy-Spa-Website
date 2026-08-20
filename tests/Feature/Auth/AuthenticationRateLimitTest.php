<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_throttle_keys_use_the_normalized_email_and_client_ip(): void
    {
        $limiter = RateLimiter::limiter('login');
        $canonicalRequest = Request::create('/login', 'POST', ['email' => 'ayu@example.com']);
        $canonicalRequest->server->set('REMOTE_ADDR', '203.0.113.10');
        $variantRequest = Request::create('/login', 'POST', ['email' => ' AYU@EXAMPLE.COM ']);
        $variantRequest->server->set('REMOTE_ADDR', '203.0.113.10');
        $differentIpRequest = Request::create('/login', 'POST', ['email' => 'ayu@example.com']);
        $differentIpRequest->server->set('REMOTE_ADDR', '203.0.113.11');

        $canonicalLimit = $limiter($canonicalRequest);
        $variantLimit = $limiter($variantRequest);
        $differentIpLimit = $limiter($differentIpRequest);

        $this->assertSame($canonicalLimit->key, $variantLimit->key);
        $this->assertNotSame($canonicalLimit->key, $differentIpLimit->key);
        $this->assertSame(5, $canonicalLimit->maxAttempts);
        $this->assertSame(60, $canonicalLimit->decaySeconds);
    }

    public function test_the_sixth_failed_login_for_the_same_normalized_email_and_ip_is_throttled(): void
    {
        User::factory()->create([
            'email' => 'ayu@example.com',
            'password' => Hash::make('calmspa9'),
        ]);

        $emailVariants = [
            'ayu@example.com',
            ' AYU@EXAMPLE.COM ',
            'Ayu@Example.Com',
            'ayu@example.com ',
            '  AYU@example.com',
        ];

        foreach ($emailVariants as $email) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->post(route('login.store'), [
                    'email' => $email,
                    'password' => 'incorrect-password',
                ])
                ->assertRedirect();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('login.store'), [
                'email' => ' ayu@EXAMPLE.com ',
                'password' => 'incorrect-password',
            ])
            ->assertTooManyRequests();
    }
}
