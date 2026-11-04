<?php

namespace App\Support\Rooms;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RoomInput
{
    public static function integer(mixed $value, int $min, int $max): bool
    {
        return (is_int($value) || (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)))
            && filter_var($value, FILTER_VALIDATE_INT) !== false && $value >= $min && $value <= $max;
    }

    public function validate(array $input, bool $update): array
    {
        $allowed = ['code', 'name', 'type', 'capacity', 'cleaningMinutes', 'isActive', 'compatibleVariantIds', ...($update ? ['version'] : [])];
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('admin.rooms.unsupported')));
        }
        $normalized = [...$input];
        foreach (['code', 'name', 'type'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $normalized[$field] = trim($input[$field]);
            }
        }
        if (($normalized['type'] ?? null) === '') {
            $normalized['type'] = null;
        }
        $integer = fn (int $min, int $max): Closure => function ($attribute, $value, $fail) use ($min, $max): void {
            if (! self::integer($value, $min, $max)) {
                $fail(__('admin.rooms.integer', ['min' => $min, 'max' => $max]));
            }
        };
        $data = Validator::make($normalized, [
            'code' => ['required', 'string', 'max:64'], 'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:64', 'regex:/\A[\pL\pN][\pL\pN _-]{0,63}\z/u'],
            'capacity' => ['required', $integer(1, 4294967295)],
            'cleaningMinutes' => ['required', $integer(0, 720)], 'isActive' => ['required', 'boolean'],
            'compatibleVariantIds' => ['present', 'array', 'max:500'],
            'compatibleVariantIds.*' => ['required', $integer(1, PHP_INT_MAX), 'distinct'],
            ...($update ? ['version' => ['required', $integer(1, 4294967295)]] : []),
        ])->validate();

        return [...$data, 'capacity' => (int) $data['capacity'], 'cleaningMinutes' => (int) $data['cleaningMinutes'],
            'type' => $data['type'] ?? null, 'isActive' => (bool) $data['isActive'],
            'compatibleVariantIds' => array_map(fn ($id) => (int) $id, $data['compatibleVariantIds']),
            ...($update ? ['version' => (int) $data['version']] : [])];
    }
}
