<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Support\ServiceCatalogPresenter;
use Illuminate\Http\Request;
use Inertia\Response;

class BookingController extends Controller
{
    public function create(Request $request, ServiceCatalogPresenter $presenter): Response
    {
        $services = Service::query()
            ->publiclyVisible()
            ->with('category')
            ->get();

        $selectedService = $request->query('service');

        return inertia('Booking', [
            'services' => $services->map($presenter->service(...))->values(),
            'selectedService' => is_string($selectedService) && $services->contains(
                fn (Service $service): bool => $service->slug === $selectedService,
            ) ? $selectedService : '',
        ]);
    }
}
