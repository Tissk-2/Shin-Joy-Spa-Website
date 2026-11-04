<?php

namespace App\Support\Scheduling;

use App\Models\BookingSettings;
use App\Support\Rooms\RoomInput;
use Illuminate\Validation\ValidationException;

final class BookingSettingsInput
{
    private const BOUNDS = ['version' => [0, 4294967295], 'review_target_minutes' => [1, 10080],
        'booking_horizon_days' => [1, 365], 'minimum_lead_minutes' => [0, 525600], 'minimum_checkout_minutes' => [1, 60]];

    public function validate(array $input): array
    {
        $allowed = [...array_keys(self::BOUNDS), 'review_intervals'];
        foreach (array_diff(array_keys($input), $allowed) as $field) {
            $this->fail((string) $field, 'unsupported');
        }
        $result = [];
        foreach (self::BOUNDS as $field => [$min, $max]) {
            if (! RoomInput::integer($input[$field] ?? null, $min, $max)) {
                throw ValidationException::withMessages([$field => __('admin.booking_settings.integer', ['min' => $min, 'max' => $max])]);
            }
            $result[$field] = (int) $input[$field];
        }
        $this->provider($result['minimum_checkout_minutes']);
        $result['review_intervals'] = $this->intervals($input['review_intervals'] ?? null);

        return $result;
    }

    public function persisted(BookingSettings $settings): array
    {
        $raw = $settings->getAttributes();
        if (! RoomInput::integer($raw['id'] ?? null, 1, 1) || ! RoomInput::integer($raw['version'] ?? null, 1, 4294967295)) {
            $this->fail('settings', 'invalid_saved');
        }
        $intervals = $settings->review_intervals;
        if (! is_array($intervals) || ! array_is_list($intervals) || count($intervals) < 1 || count($intervals) > 168) {
            $this->fail('settings', 'invalid_saved');
        }
        $public = array_map(function ($row): array {
            if (! is_array($row) || count($row) !== 3 || array_diff(array_keys($row), ['weekday', 'start_minute', 'end_minute']) !== []
                || ! is_int($row['weekday'] ?? null) || ! is_int($row['start_minute'] ?? null) || ! is_int($row['end_minute'] ?? null)
                || $row['weekday'] < 1 || $row['weekday'] > 7 || $row['start_minute'] < 0 || $row['start_minute'] > 1439
                || $row['end_minute'] < 1 || $row['end_minute'] > 1440) {
                $this->fail('settings', 'invalid_saved');
            }

            return ['weekday' => $row['weekday'], 'start' => self::time($row['start_minute']), 'end' => self::time($row['end_minute'])];
        }, $intervals);
        $input = $this->validate([...array_intersect_key($raw, self::BOUNDS), 'review_intervals' => $public]);
        // JSON object key order is immaterial. Preserve interval-list order and
        // already checked integer types when comparing canonical saved values.
        $canonical = array_map(fn ($row) => ['weekday' => $row['weekday'],
            'start_minute' => $row['start_minute'], 'end_minute' => $row['end_minute']], $intervals);
        if ($input['review_intervals'] !== $canonical) {
            $this->fail('settings', 'invalid_saved');
        }

        return $input;
    }

    private function intervals(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) < 1 || count($rows) > 168) {
            $this->fail('review_intervals', 'intervals');
        }
        $normalized = [];
        foreach ($rows as $index => $row) {
            $field = 'review_intervals.'.$index;
            if (! is_array($row) || count($row) !== 3 || array_diff(array_keys($row), ['weekday', 'start', 'end']) !== []) {
                $this->fail($field, 'interval');
            }
            if (! RoomInput::integer($row['weekday'] ?? null, 1, 7)) {
                $this->fail($field.'.weekday', 'weekday');
            }
            $start = $this->minute($row['start'] ?? null, false, $field.'.start');
            $end = $this->minute($row['end'] ?? null, true, $field.'.end');
            if ($start >= $end) {
                $this->fail($field.'.end', 'end');
            }
            $normalized[] = ['weekday' => (int) $row['weekday'], 'start_minute' => $start, 'end_minute' => $end];
        }
        usort($normalized, fn ($a, $b) => [$a['weekday'], $a['start_minute']] <=> [$b['weekday'], $b['start_minute']]);
        foreach ($normalized as $index => $row) {
            $previous = $normalized[$index - 1] ?? null;
            if ($previous && $previous['weekday'] === $row['weekday'] && $previous['end_minute'] > $row['start_minute']) {
                $this->fail('review_intervals', 'overlap');
            }
        }

        return $normalized;
    }

    private function minute(mixed $time, bool $end, string $field): int
    {
        if ($end && $time === '24:00') {
            return 1440;
        }
        if (! is_string($time) || ! preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $time)) {
            $this->fail($field, 'time');
        }

        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    private function provider(int $checkout): void
    {
        if (config('booking.online_payments_enabled') !== false) {
            $minimum = config('booking.minimum_provider_checkout_minutes');
            if (config('booking.online_payments_enabled') !== true || ! RoomInput::integer($minimum, 1, 60) || $checkout < (int) $minimum) {
                $this->fail('minimum_checkout_minutes', 'provider');
            }
        }
    }

    public static function time(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    private function fail(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __('admin.booking_settings.'.$key)]);
    }
}
