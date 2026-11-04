<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingAvailabilityRequest;
use App\Queries\RoomAvailabilityQuery;
use App\Support\Scheduling\BookingAvailabilityUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class BookingAvailabilityController
{
    public function __invoke(BookingAvailabilityRequest $request, RoomAvailabilityQuery $query): JsonResponse
    {
        try {
            return response()->json($query->forSelection($request->selection()))
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
