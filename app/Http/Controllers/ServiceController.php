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

    public function show(string $slug, PublicCatalogQuery $catalog, ServiceCatalogPresenter $presenter): Response
    {
        $service = $catalog->findService($slug);
        $related = $catalog->related($service);

        return inertia('ServiceDetail', [
            'service' => $presenter->package($service),
            'related' => $related->map($presenter->link(...))->values(),
        ]);
    }
}
