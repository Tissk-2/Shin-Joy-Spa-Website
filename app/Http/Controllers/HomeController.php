<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\ServiceCatalogPresenter;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(ServiceCatalogPresenter $presenter): Response
    {
        return inertia('Home', [
            'categories' => ServiceCategory::query()
                ->active()
                ->get()
                ->map($presenter->category(...))
                ->values(),
            'services' => Service::query()
                ->publiclyVisible()
                ->with('category')
                ->get()
                ->map($presenter->service(...))
                ->values(),
        ]);
    }
}
