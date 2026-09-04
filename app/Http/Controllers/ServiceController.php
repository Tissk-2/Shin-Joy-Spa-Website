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
            ->with([
                'variants' => fn ($query) => $query->active(),
                'extensions' => fn ($query) => $query->active(),
            ])
            ->where('slug', $slug)
            ->firstOrFail();

        $comparison = Service::query()
            ->publiclyVisible()
            ->whereKeyNot($service)
            ->first();

        return inertia('ServiceDetail', [
            'service' => $presenter->package($service),
            'comparison' => $comparison ? $presenter->link($comparison) : null,
        ]);
    }
}
