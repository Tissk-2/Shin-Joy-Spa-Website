<?php

namespace App\Queries;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

final class PublicCatalogQuery
{
    /** @return Collection<int, ServiceCategory> */
    public function categories(): Collection
    {
        return ServiceCategory::query()
            ->publiclyVisible()
            ->with(['services' => fn (HasMany $services) => $services
                ->publiclyVisible()
                ->withPublicChoices()])
            ->get();
    }

    public function findService(string $slug): Service
    {
        return Service::query()
            ->publiclyVisible()
            ->withPublicChoices()
            ->where('slug', $slug)
            ->firstOrFail();
    }
}
