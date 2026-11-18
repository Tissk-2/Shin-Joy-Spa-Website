<?php

namespace App\Queries;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class AdminBookingIndexQuery
{
    /**
     * @param  array{scope: string, status: ?string, q: string, dateFrom: ?string, dateTo: ?string}  $filters
     * @return LengthAwarePaginator<int, Booking>
     */
    public function paginate(array $filters, CarbonImmutable $now): LengthAwarePaginator
    {
        $query = Booking::query();

        $this->applyScope($query, $filters['scope'], $now);
        $this->applySearch($query, $filters['q']);

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['dateFrom'] !== null) {
            $query->whereDate('appointment_date', '>=', $filters['dateFrom']);
        }

        if ($filters['dateTo'] !== null) {
            $query->whereDate('appointment_date', '<=', $filters['dateTo']);
        }

        // The response queue is first come, first served; every other tab is by appointment.
        if ($filters['scope'] === 'pending') {
            $query->orderBy('created_at')->orderBy('id');
        } else {
            $direction = in_array($filters['scope'], ['upcoming', 'today'], true) ? 'asc' : 'desc';
            $query->orderBy('appointment_date', $direction)
                ->orderBy('appointment_time', $direction)
                ->orderBy('id', $direction);
        }

        return $query
            ->paginate(20)
            ->appends(array_filter(
                $filters,
                fn ($value): bool => $value !== null && $value !== '',
            ));
    }

    /**
     * One count per tab, before search and filters.
     *
     * @return array{upcoming: int, pending: int, today: int, past: int, all: int}
     */
    public function counts(CarbonImmutable $now): array
    {
        $counts = [];
        foreach (['upcoming', 'pending', 'today', 'past', 'all'] as $scope) {
            $query = Booking::query();
            $this->applyScope($query, $scope, $now);
            $counts[$scope] = $query->count();
        }

        return $counts;
    }

    private function applyScope(Builder $query, string $scope, CarbonImmutable $now): void
    {
        if ($scope === 'all') {
            return;
        }

        if ($scope === 'pending') {
            $query->where('status', BookingStatus::Pending->value);

            return;
        }

        // Like the Today page: the day's appointments, without the cancelled ones.
        if ($scope === 'today') {
            $query->whereDate('appointment_date', $now->toDateString())
                ->where('status', '!=', BookingStatus::Cancelled->value);

            return;
        }

        $date = $now->toDateString();
        $time = $now->format('H:i');

        $query->where(function (Builder $nested) use ($scope, $date, $time): void {
            if ($scope === 'upcoming') {
                $nested->whereDate('appointment_date', '>', $date)
                    ->orWhere(function (Builder $today) use ($date, $time): void {
                        $today->whereDate('appointment_date', $date)
                            ->where('appointment_time', '>=', $time);
                    });

                return;
            }

            $nested->whereDate('appointment_date', '<', $date)
                ->orWhere(function (Builder $today) use ($date, $time): void {
                    $today->whereDate('appointment_date', $date)
                        ->where('appointment_time', '<', $time);
                });
        });
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $pattern = $this->escapedPattern($search);
        $query->where(function (Builder $nested) use ($pattern): void {
            foreach (['reference', 'customer_name', 'customer_email', 'customer_phone'] as $column) {
                $method = $column === 'reference' ? 'whereRaw' : 'orWhereRaw';
                $nested->{$method}("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }

    private function escapedPattern(string $value): string
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value));

        return "%{$escaped}%";
    }
}
