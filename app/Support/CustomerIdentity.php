<?php

namespace App\Support;

use Illuminate\Support\Str;

final class CustomerIdentity
{
    public static function name(string $value): string
    {
        return Str::of($value)->squish()->toString();
    }

    public static function email(string $value): string
    {
        return Str::lower(trim($value));
    }

    public static function phone(string $value): string
    {
        $trimmed = trim($value);
        $prefix = str_starts_with($trimmed, '+') ? '+' : '';

        return $prefix.preg_replace('/\D+/', '', $trimmed);
    }
}
