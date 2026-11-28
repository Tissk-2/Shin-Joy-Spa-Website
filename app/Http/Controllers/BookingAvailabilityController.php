<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingAvailabilityRequest;
use App\Policies\BookingPolicy;
use App\Queries\RoomAvailabilityQuery;
use App\Support\Scheduling\BookingAvailabilityUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class BookingAvailabilityController
{
    public function __invoke(BookingAvailabilityRequest $request, RoomAvailabilityQuery $query): JsonResponse
    {
        return $this->respond(fn (): array => $query->forSelection($request->selection(), false));
    }

    /** The staff booking form's times: no minimum notice, for walk-ins and calls. */
    public function staff(BookingAvailabilityRequest $request, RoomAvailabilityQuery $query): JsonResponse
    {
        abort_unless((new BookingPolicy)->create($request->user()), 403);

        return $this->respond(fn (): array => $query->forSelection($request->selection(), true));
    }

    /** Full dates in a visible calendar month for the customer booking form. */
    public function month(BookingAvailabilityRequest $request, RoomAvailabilityQuery $query): JsonResponse
    {
        return $this->respond(fn (): array => $query->forMonth($request->monthSelection(), false));
    }

    /** Full dates in a visible calendar month for the staff booking form (no customer notice). */
    public function staffMonth(BookingAvailabilityRequest $request, RoomAvailabilityQuery $query): JsonResponse
    {
        abort_unless((new BookingPolicy)->create($request->user()), 403);

        return $this->respond(fn (): array => $query->forMonth($request->monthSelection(), true));
    }

    /** @param callable(): array<string, mixed> $resolve */
    private function respond(callable $resolve): JsonResponse
    {
        try {
            return response()->json($resolve())
                ->header('Cache-Control', 'private, no-store');
        } catch (BookingAvailabilityUnavailable) {
            return response()->json(['message' => __('validation.availability.unavailable'),
                'code' => 'availability_unavailable'], 503)->header('Cache-Control', 'private, no-store');
        } catch (ValidationException $error) {
            return response()->json(['message' => __('validation.availability.invalid'),
                'errors' => $error->errors()], 422)->header('Cache-Control', 'private, no-store');
        }
    }
}
