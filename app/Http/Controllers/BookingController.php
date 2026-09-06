<?php

namespace App\Http\Controllers;

use App\Actions\CreateBooking;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Service;
use App\Support\BookingSelectionResolver;
use App\Support\ServiceCatalogPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

class BookingController extends Controller
{
    public function create(Request $request, ServiceCatalogPresenter $presenter, BookingSelectionResolver $selectionResolver): Response
    {
        $services = Service::query()
            ->publiclyVisible()
            ->with([
                'variants' => fn ($query) => $query->active(),
                'extensions' => fn ($query) => $query->active(),
            ])
            ->get();

        return inertia('Booking', [
            'packages' => $services->map($presenter->package(...))->values(),
            'selection' => $selectionResolver->resolve($request, $services),
            'submissionKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreBookingRequest $request, CreateBooking $createBooking): RedirectResponse
    {
        $booking = $createBooking($request->user(), $request->validatedBookingData());

        return redirect('/bookings/'.$booking->reference, 303);
    }
}
