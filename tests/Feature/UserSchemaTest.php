<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_stores_phone_and_email_verification_state(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'phone',
            'email_verified_at',
        ]));
    }

    public function test_users_are_email_verifiable_and_do_not_serialize_authentication_secrets(): void
    {
        $user = new User([
            'name' => 'Ayu Lestari',
            'email' => 'ayu@example.com',
            'phone' => '+6281234567890',
            'password' => 'secret',
        ]);
        $user->setRememberToken('remember-me');

        $this->assertInstanceOf(MustVerifyEmail::class, $user);
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }
}
