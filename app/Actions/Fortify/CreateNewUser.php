<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\CustomerIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $normalized = [
            'name' => CustomerIdentity::name((string) ($input['name'] ?? '')),
            'email' => CustomerIdentity::email((string) ($input['email'] ?? '')),
            'phone' => CustomerIdentity::phone((string) ($input['phone'] ?? '')),
            'password' => $input['password'] ?? null,
            'password_confirmation' => $input['password_confirmation'] ?? null,
        ];

        Validator::make($normalized, [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:254', Rule::unique(User::class)],
            'phone' => ['required', 'regex:/^\+?\d{8,15}$/'],
            'password' => $this->passwordRules(),
        ])->validate();

        try {
            return DB::transaction(fn (): User => User::create([
                'name' => $normalized['name'],
                'email' => $normalized['email'],
                'phone' => $normalized['phone'],
                'password' => Hash::make($normalized['password']),
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isEmailUniqueConstraint($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'email' => [trans('validation.unique', ['attribute' => trans('validation.attributes.email')])],
            ]);
        }
    }

    private function isEmailUniqueConstraint(UniqueConstraintViolationException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'users_email_unique')
            || str_contains($message, 'unique constraint failed: users.email');
    }
}
