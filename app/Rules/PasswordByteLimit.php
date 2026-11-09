<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PasswordByteLimit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Bcrypt ignores bytes past 72; other hashers have no such limit.
        if (config('hashing.driver') === 'bcrypt' && is_string($value) && strlen($value) > 72) {
            $fail('validation.custom.password.bytes')->translate();
        }
    }
}
