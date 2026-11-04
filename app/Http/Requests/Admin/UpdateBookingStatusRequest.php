<?php

namespace App\Http\Requests\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateBookingStatusRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = ['currentStatus', 'nextStatus', 'reason', 'operationalConfirmed'];

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => is_string($this->input('reason'))
                ? trim($this->input('reason')) ?: null
                : $this->input('reason'),
        ]);
    }

    public function authorize(): bool
    {
        $booking = $this->route('booking');

        return $booking instanceof Booking && $this->user()?->can('updateStatus', $booking) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'currentStatus' => ['required', Rule::enum(BookingStatus::class)],
            'nextStatus' => ['required', Rule::enum(BookingStatus::class)],
            'reason' => ['nullable', 'string', 'max:500'],
            'operationalConfirmed' => [Rule::requiredIf(config('booking.scheduling_enabled') && $this->input('currentStatus') === 'pending' && $this->input('nextStatus') === 'confirmed'), 'boolean', Rule::when(config('booking.scheduling_enabled') && $this->input('currentStatus') === 'pending' && $this->input('nextStatus') === 'confirmed', ['accepted'])],
        ];
    }

    public function messages(): array
    {
        return ['operationalConfirmed.required' => __('admin.status.operational_required'), 'operationalConfirmed.accepted' => __('admin.status.operational_required')];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $unknownFields = array_diff(array_keys($this->all()), self::ALLOWED_FIELDS);

                foreach ($unknownFields as $field) {
                    $validator->errors()->add($field, __('admin.status.unsupported_field'));
                }
            },
        ];
    }
}
