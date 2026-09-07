<?php

namespace App\Http\Requests\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBookingStatusRequest extends FormRequest
{
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
        ];
    }
}
