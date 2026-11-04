<?php

namespace App\Support;

use App\Queries\PublicCatalogQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

final class BookingAuthContextResolver
{
    public function __construct(private readonly PublicCatalogQuery $catalog) {}

    /**
     * @return array{slug: string, nameId: string, nameEn: string, startingPriceIdr: int}|null
     */
    public function resolve(Request $request): ?array
    {
        $intended = SafeIntendedPath::peek($request);

        if ($intended === null) {
            return null;
        }

        $path = parse_url($intended, PHP_URL_PATH);

        if (! is_string($path) || $path !== route('booking', absolute: false)) {
            return null;
        }

        $query = [];
        parse_str((string) parse_url($intended, PHP_URL_QUERY), $query);
        $slug = $query['service'] ?? null;

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        try {
            $service = $this->catalog->findService($slug);
        } catch (ModelNotFoundException) {
            return null;
        }

        $startingPrice = $service->variants->min('price_idr');

        if (! is_numeric($startingPrice)) {
            return null;
        }

        return [
            'slug' => $service->slug,
            'nameId' => $service->name_id,
            'nameEn' => $service->name_en,
            'startingPriceIdr' => (int) $startingPrice,
        ];
    }
}
