<?php

namespace App\Support\Scheduling;

use App\Models\BookingSettings;
use Carbon\CarbonImmutable;

final class BookingWindow
{
    public static function deadline(CarbonImmutable $approvedAt, CarbonImmutable $startsAt): CarbonImmutable
    {
        return $approvedAt->addMinutes(60)->min($startsAt)->utc();
    }

    public static function reviewDeadline(CarbonImmutable $submittedAt, BookingSettings $settings): CarbonImmutable
    {
        $input = (new BookingSettingsInput)->persisted($settings);
        $cursor = $submittedAt->setTimezone('Asia/Jakarta');
        $week = $cursor->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $remaining = $input['review_target_minutes'] * 60 * 1000000;
        $weekly = array_sum(array_map(fn ($row) => $row['end_minute'] - $row['start_minute'], $input['review_intervals'])) * 60 * 1000000;
        // Consume the current partial week first. Then skip whole weeks while
        // retaining the last budget unit so an exact close stays on that week.
        $result = self::consume($cursor, $week, $input['review_intervals'], $remaining);
        if ($result instanceof CarbonImmutable) {
            return $result->utc();
        }
        $weeks = intdiv($result - 1, $weekly);
        $week = $week->addWeeks($weeks + 1);
        $deadline = self::consume($week, $week, $input['review_intervals'], $result - $weeks * $weekly);

        return $deadline->utc();
    }

    private static function consume(CarbonImmutable $cursor, CarbonImmutable $week, array $intervals, int $remaining): CarbonImmutable|int
    {
        foreach ($intervals as $row) {
            $day = $week->addDays($row['weekday'] - 1);
            $start = $day->addMinutes($row['start_minute']);
            $end = $day->addMinutes($row['end_minute']);
            $from = $cursor->max($start);
            if ($from->greaterThanOrEqualTo($end)) {
                continue;
            }
            $available = ((int) $end->format('U') - (int) $from->format('U')) * 1000000
                + (int) $end->format('u') - (int) $from->format('u');
            if ($remaining <= $available) {
                return $from->addMicroseconds($remaining);
            }
            $remaining -= $available;
        }

        return $remaining;
    }
}
