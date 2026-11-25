<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use App\Support\CustomerIdentity;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Checked like at sign-up; left out, the number stays as it is.
            'phone' => ['sometimes', 'required', 'regex:/^\+?\d{8,15}$/'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // A new email lets its owner reset the password, so a session alone must not be enough.
            // Ignored while the email is unchanged, so a stale value can't block a name edit.
            'current_password' => [
                Rule::excludeIf(fn (): bool => $this->input('email') === $this->user()->email),
                'bail',
                'required',
                'string',
                'current_password',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => CustomerIdentity::phone($this->input('phone'))]);
        }
    }
}
