<?php

namespace Tests\Unit;

use App\Rules\PasswordByteLimit;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PasswordByteLimitTest extends TestCase
{
    public function test_a_72_byte_password_passes_with_bcrypt(): void
    {
        config(['hashing.driver' => 'bcrypt']);
        $password = str_repeat('a', 71).'1';
        $this->assertSame(72, strlen($password));

        $this->assertFalse($this->fails($password));
    }

    public function test_a_73_byte_password_fails_with_bcrypt(): void
    {
        config(['hashing.driver' => 'bcrypt']);
        $password = str_repeat('a', 72).'1';
        $this->assertSame(73, strlen($password));

        $this->assertTrue($this->fails($password));
    }

    public function test_long_passwords_are_allowed_with_other_hashers(): void
    {
        config(['hashing.driver' => 'argon2id']);
        $password = str_repeat('a', 72).'1';
        $this->assertSame(73, strlen($password));

        $this->assertFalse($this->fails($password));
    }

    public function test_non_string_values_are_ignored(): void
    {
        config(['hashing.driver' => 'bcrypt']);

        $this->assertFalse($this->fails(['not-a-string']));
    }

    private function fails(mixed $value): bool
    {
        return Validator::make(['password' => $value], ['password' => [new PasswordByteLimit]])->fails();
    }
}
