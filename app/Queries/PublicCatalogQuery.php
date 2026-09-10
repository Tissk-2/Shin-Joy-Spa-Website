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

    /** @return Collection<int, Service> */
    public function related(Service $service, int $limit = 3): Collection
    {
        return Service::query()
            ->publiclyVisible()
            ->where('category_id', $service->category_id)
            ->whereKeyNot($service)
            ->limit($limit)
            ->get();
    }
}
