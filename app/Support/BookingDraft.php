<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * A booking form sent after the session ended goes to sign-in, and what was typed would be lost.
 * The choices wait in the new session on the server (the public pages keep nothing in the
 * browser) and fill the form once after sign-in, only for the account whose name or WhatsApp
 * number was on the form, since the notes may be personal and the device may be shared.
 */
final class BookingDraft
{
    private const KEY = 'booking.draft';

    public static function keep(Request $request): void
    {
        if (! $request->isMethod('POST') || ! $request->routeIs('bookings.store') || ! $request->hasSession()) {
            return;
        }

        $request->session()->put(self::KEY, [
            'selection' => [
                'service' => self::text($request, 'service', 80),
                'mode' => self::text($request, 'mode', 40),
                'duration' => self::number($request, 'duration', 480),
                'extension' => self::text($request, 'extension', 80),
            ],
            'details' => [
                'date' => self::matching($request, 'date', '/^\d{4}-\d{2}-\d{2}$/'),
                'time' => self::matching($request, 'time', '/^\d{2}:\d{2}$/'),
                'guests' => self::number($request, 'guests', 4),
                'notes' => self::text($request, 'notes', 1000),
            ],
            'name' => self::text($request, 'name', 100),
            'phone' => self::text($request, 'phone', 30),
        ]);
    }

    /**
     * @return array{selection: array<string, string>, details: array{date: ?string, time: ?string, guests: ?int, notes: ?string}}|null
     */
    public static function pull(Request $request): ?array
    {
        $draft = $request->session()->pull(self::KEY);
        $user = $request->user();

        if (! is_array($draft) || $user === null) {
            return null;
        }

        $sameName = is_string($draft['name'] ?? null) && CustomerIdentity::name($draft['name']) === $user->name;
        $samePhone = is_string($draft['phone'] ?? null) && $user->phone !== null
            && CustomerIdentity::phone($draft['phone']) === $user->phone;

        if (! $sameName && ! $samePhone) {
            return null;
        }

        return [
            // As text, the way the booking page reads its address; the resolver checks it against the menu.
            'selection' => array_map(fn (mixed $value): string => (string) $value, array_filter(
                $draft['selection'],
                fn (mixed $value): bool => $value !== null,
            )),
            'details' => $draft['details'],
        ];
    }

    private static function text(Request $request, string $key, int $max): ?string
    {
        $value = $request->input($key);

        return is_string($value) && mb_strlen($value) <= $max ? $value : null;
    }

    private static function matching(Request $request, string $key, string $pattern): ?string
    {
        $value = $request->input($key);

        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }

    private static function number(Request $request, string $key, int $max): ?int
    {
        $value = filter_var($request->input($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);

        return $value === false ? null : $value;
    }
}
