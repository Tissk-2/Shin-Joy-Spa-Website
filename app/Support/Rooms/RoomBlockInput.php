<?php

namespace App\Support\Rooms;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RoomBlockInput
{
    public function validate(array $input, bool $update, bool $allRooms = false): array
    {
        $allowed = ['startsAt', 'endsAt', 'reason', ...($allRooms ? [] : ['roomId']), ...($update ? ['version'] : [])];
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('admin.rooms.unsupported')));
        }
        $normalized = [...$input, 'reason' => is_string($input['reason'] ?? null) ? trim($input['reason']) : ($input['reason'] ?? null)];
        $integer = fn ($attribute, $value, $fail) => RoomInput::integer($value, 1, $attribute === 'version' ? 4294967295 : PHP_INT_MAX)
            ?: $fail(__('admin.blocks.invalid'));
        $date = function ($attribute, $value, $fail): void {
            if (! is_string($value) || ! preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/', $value)) {
                $fail(__('admin.blocks.date'));

                return;
            }
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, 'Asia/Jakarta');
                $year = (int) $parsed->setTimezone('UTC')->format('Y');
                if ($parsed->format('Y-m-d\TH:i') !== $value || $year < 1000 || $year > 9999) {
                    $fail(__('admin.blocks.date'));
                }
            } catch (\Throwable) {
                $fail(__('admin.blocks.date'));
            }
        };
        $data = Validator::make($normalized, [
            ...($allRooms ? [] : ['roomId' => ['required', $integer]]),
            'startsAt' => ['required', $date], 'endsAt' => ['required', $date], 'reason' => ['required', 'string', 'max:1000'],
            ...($update ? ['version' => ['required', $integer]] : []),
        ])->validate();

        return [...$data, ...($allRooms ? [] : ['roomId' => (int) $data['roomId']]),
            'start' => CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['startsAt'], 'Asia/Jakarta')->setTimezone('UTC'),
            'end' => CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['endsAt'], 'Asia/Jakarta')->setTimezone('UTC'),
            ...($update ? ['version' => (int) $data['version']] : [])];
    }
}
