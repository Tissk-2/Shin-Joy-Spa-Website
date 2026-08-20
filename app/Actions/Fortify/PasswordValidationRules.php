<?php

namespace App\Actions\Fortify;

use Illuminate\Contracts\Validation\Rule;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Rule|array<mixed>|string>
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
        ];
    }
}
