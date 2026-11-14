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
        return $this->respond($request, $query, false);
    }

    /** The staff booking form's times: no minimum notice, for walk-ins and calls. */
    public function staff(BookingAvailabilityRequest $request, RoomAvailabilityQuery $query): JsonResponse
    {
        abort_unless((new BookingPolicy)->create($request->user()), 403);

        return $this->respond($request, $query, true);
    }

    private function respond(BookingAvailabilityRequest $request, RoomAvailabilityQuery $query, bool $staffEntry): JsonResponse
    {
        try {
            return response()->json($query->forSelection($request->selection(), $staffEntry))
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
