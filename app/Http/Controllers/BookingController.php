<?php

namespace App\Http\Controllers;

use App\Actions\CreateBooking;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Queries\PublicCatalogQuery;
use App\Support\BookingPresenter;
use App\Support\BookingSelectionResolver;
use App\Support\ServiceCatalogPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

class BookingController extends Controller
{
    public function index(Request $request, BookingPresenter $presenter): Response
    {
        $groups = $presenter->groupedIndex(
            Booking::query()
                ->where('user_id', $request->user()->id)
                ->get()
        );

        return inertia('bookings/Index', $groups);
    }

    public function create(
        Request $request,
        PublicCatalogQuery $catalog,
        ServiceCatalogPresenter $presenter,
        BookingSelectionResolver $selectionResolver,
    ): Response {
        $services = $catalog->categories()->flatMap->services;

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

    public function show(Request $request, BookingPresenter $presenter, string $reference): Response
    {
        $booking = Booking::query()
            ->where('user_id', $request->user()->id)
            ->where('reference', $reference)
            ->firstOrFail();

        return inertia('bookings/Show', [
            'booking' => $presenter->detail($booking),
        ]);
    }
}
