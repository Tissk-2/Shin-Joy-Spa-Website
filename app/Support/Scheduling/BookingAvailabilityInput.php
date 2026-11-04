<?php

namespace App\Support\Scheduling;

use App\Enums\TreatmentMode;
use App\Support\Rooms\RoomInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BookingAvailabilityInput
{
    public function validate(array $input): array
    {
        $unknown = array_diff(array_keys($input), ['service', 'mode', 'duration', 'extension', 'date', 'guests', 'room_type']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('validation.availability.unsupported')));
        }
        $normalized = [...$input];
        foreach (['extension', 'room_type'] as $field) {
            $normalized[$field] = ($input[$field] ?? null) === '' ? null : ($input[$field] ?? null);
        }
        $integer = fn (int $max) => function ($attribute, $value, $fail) use ($max): void {
            if (! RoomInput::integer($value, 1, $max)) {
                $fail(__('validation.availability.integer'));
            }
        };
        $date = function ($attribute, $value, $fail): void {
            if (! is_string($value) || ! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts)
                || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $fail(__('validation.availability.date'));
            }
        };
        $data = Validator::make($normalized, [
            'service' => ['bail', 'required', 'string', 'max:80'],
            'mode' => ['bail', 'required', 'string', Rule::in(array_column(TreatmentMode::cases(), 'value'))],
            'duration' => ['bail', 'required', $integer(480)],
            'guests' => ['bail', 'required', $integer(4)],
            'date' => ['bail', 'required', $date],
            'extension' => ['bail', 'nullable', 'string', 'max:80'],
            'room_type' => ['bail', 'nullable', 'string', 'regex:/\A[\pL\pN][\pL\pN _-]{0,63}\z/u'],
        ])->validate();

        return [...$data, 'duration' => (int) $data['duration'], 'guests' => (int) $data['guests']];
    }
}
