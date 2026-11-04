<?php

namespace App\Http\Controllers;

use App\Queries\PublicCatalogQuery;
use App\Support\ServiceCatalogPresenter;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(PublicCatalogQuery $catalog, ServiceCatalogPresenter $presenter): Response
    {
        return inertia('Services', [
            'categories' => $catalog->categories()
                ->map($presenter->category(...))
                ->values(),
        ]);
    }
}
