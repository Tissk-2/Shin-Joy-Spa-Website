<?php

namespace App\Support\Catalog;

use App\Data\Admin\Catalog\CatalogPackageData;
use App\Models\Service;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class CatalogCollisionReporter
{
    public function report(QueryException $exception, CatalogPackageData $data, ?Service $service = null): never
    {
        $slugOwner = Service::query()->where('slug', $data->slug)->first();
        if ($slugOwner !== null && ($service === null || $slugOwner->id !== $service->id)) {
            throw ValidationException::withMessages([
                'slug' => __('validation.unique', ['attribute' => 'slug']),
            ]);
        }

        foreach ([
            ['variants', ServiceVariant::class, $data->variants],
            ['extensions', ServiceExtension::class, $data->extensions],
        ] as [$field, $model, $choices]) {
            foreach ($choices as $index => $choice) {
                $owner = $model::query()->where('code', $choice->code)->first();
                if ($owner !== null && ($choice->id === null || $owner->id !== $choice->id)) {
                    throw ValidationException::withMessages([
                        "{$field}.{$index}.code" => __('validation.unique', ['attribute' => 'code']),
                    ]);
                }
            }
        }

        throw $exception;
    }
}
