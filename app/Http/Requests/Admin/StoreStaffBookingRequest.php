<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Support\CustomerIdentity;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreStaffBookingRequest extends StoreBookingRequest
{
    public const SOURCES = ['walk_in', 'phone', 'whatsapp'];

    public function authorize(): bool
    {
        // Fail before validation so field errors never reach an admin without booking permission;
        // CreateStaffBooking re-checks under the staff guard.
        return $this->user()?->can('create', Booking::class) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // A staff booking has no account, so it needs at least one way to reach the customer.
            'phone' => [...parent::rules()['phone'], 'required_without:email'],
            'email' => ['bail', 'nullable', 'string', 'email', 'max:254'],
            'source' => ['bail', 'required', 'string', Rule::in(self::SOURCES)],
            'approve' => ['bail', 'required', 'boolean'],
            'operationalConfirmed' => ['bail', 'sometimes', 'boolean'],
        ];
    }

    protected function hasExistingSubmission(): bool
    {
        $key = $this->input('submission_key');
        $user = $this->user();

        return $user !== null
            && is_string($key)
            && Str::isUuid($key)
            && Booking::query()->where('created_by_staff_id', $user->id)->where('submission_key', $key)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedStaffBookingData(): array
    {
        $validated = $this->validated();
        $email = is_string($validated['email'] ?? null) ? trim($validated['email']) : '';

        return [
            ...self::validatedBookingDataFrom($validated),
            'email' => $email === '' ? null : CustomerIdentity::email($email),
            'source' => (string) $validated['source'],
        ];
    }
}
