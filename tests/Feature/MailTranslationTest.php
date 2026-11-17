<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Laravel's mail layout adds a closing, a link fallback and a copyright line
// of its own; Indonesian emails used to show them in English.
class MailTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_indonesian_account_emails_have_no_english_layout_text(): void
    {
        $user = User::factory()->unverified()->create();
        app()->setLocale('id');

        foreach ([new VerifyEmail, new ResetPassword('test-token')] as $notification) {
            $html = (string) $notification->toMail($user)->render();

            $this->assertStringContainsString('Hormat kami,', $html, $notification::class);
            $this->assertStringContainsString('Jika Anda kesulitan mengeklik tombol', $html, $notification::class);
            $this->assertStringContainsString('Hak cipta dilindungi undang-undang.', $html, $notification::class);
            $this->assertStringNotContainsString('Regards,', $html, $notification::class);
            $this->assertStringNotContainsString('having trouble clicking', $html, $notification::class);
            $this->assertStringNotContainsString('All rights reserved.', $html, $notification::class);
        }
    }

    public function test_english_account_emails_keep_the_english_layout_text(): void
    {
        $user = User::factory()->unverified()->create();
        app()->setLocale('en');

        $html = (string) (new VerifyEmail)->toMail($user)->render();

        $this->assertStringContainsString('Regards,', $html);
        $this->assertStringContainsString('having trouble clicking', $html);
        $this->assertStringContainsString('All rights reserved.', $html);
    }
}
