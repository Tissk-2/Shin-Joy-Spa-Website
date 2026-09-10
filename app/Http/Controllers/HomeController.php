<?php

namespace App\Http\Controllers;

use App\Queries\PublicCatalogQuery;
use App\Support\ServiceCatalogPresenter;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(PublicCatalogQuery $catalog, ServiceCatalogPresenter $presenter): Response
    {
        $categories = $catalog->categories();

        return inertia('Home', [
            'categories' => $categories
                ->map($presenter->category(...))
                ->values(),
            'packages' => $categories
                ->flatMap->services
                ->map($presenter->package(...))
                ->values(),
        ]);
    }
}
