<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Support\ServiceCatalogPresenter;
use Inertia\Response;

class ServiceController extends Controller
{
    public function show(string $slug, ServiceCatalogPresenter $presenter): Response
    {
        $service = Service::query()
            ->publiclyVisible()
            ->with('category')
            ->where('slug', $slug)
            ->firstOrFail();

        $related = Service::query()
            ->publiclyVisible()
            ->with('category')
            ->where('category_id', $service->category_id)
            ->whereKeyNot($service)
            ->limit(3)
            ->get();

        return inertia('ServiceDetail', [
            'service' => $presenter->service($service),
            'category' => $presenter->category($service->category),
            'related' => $related->map($presenter->service(...))->values(),
        ]);
    }
}
