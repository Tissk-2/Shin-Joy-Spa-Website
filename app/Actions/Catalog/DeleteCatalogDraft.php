<?php

namespace App\Actions\Catalog;

use App\Models\Booking;
use App\Models\Service;
use App\Support\Catalog\CatalogImageStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteCatalogDraft
{
    public function __construct(private readonly CatalogImageStorage $images) {}

    public function __invoke(Service $service, int $version): void
    {
        DB::transaction(function () use ($service, $version): void {
            $locked = Service::query()->whereKey($service->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->lock_version !== $version) {
                throw ValidationException::withMessages([
                    'version' => SaveCatalogPackage::staleValidationMessage(),
                ]);
            }

            $variantIds = $locked->variants()->lockForUpdate()->get(['id'])->modelKeys();
            $extensionIds = $locked->extensions()->lockForUpdate()->get(['id'])->modelKeys();
            $referenced = Booking::query()
                ->where('service_id', $locked->id)
                ->orWhereIn('service_variant_id', $variantIds)
                ->orWhereIn('service_extension_id', $extensionIds)
                ->exists();

            if ($locked->published_at !== null || $referenced) {
                throw ValidationException::withMessages([
                    'status' => __('validation.in', ['attribute' => 'status']),
                ]);
            }

            $paths = array_values(array_filter([$locked->image_path, ...($locked->gallery_paths ?? [])]));
            $locked->lock_version++;
            $locked->save();
            $locked->delete();
            DB::afterCommit(fn () => $this->images->deleteManaged($paths));
        });
    }
}
