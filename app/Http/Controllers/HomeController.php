<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Support\ServiceCatalogPresenter;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(ServiceCatalogPresenter $presenter): Response
    {
        return inertia('Home', [
            'packages' => Service::query()
                ->publiclyVisible()
                ->with([
                    'variants' => fn ($query) => $query->active(),
                    'extensions' => fn ($query) => $query->active(),
                ])
                ->get()
                ->map($presenter->package(...))
                ->values(),
        ]);
    }
}
