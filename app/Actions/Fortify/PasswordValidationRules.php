<?php

namespace App\Actions\Fortify;

use App\Rules\PasswordByteLimit;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Rule|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return [
            'required',
            'string',
            'min:8',
            'max:255',
            'regex:/[A-Za-z]/',
            'regex:/\d/',
            'confirmed',
            new PasswordByteLimit,
        ];
    }
}
