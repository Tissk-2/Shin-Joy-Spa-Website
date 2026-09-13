<?php

namespace App\Actions\Catalog;

use App\Data\Admin\Catalog\CatalogChoiceData;
use App\Data\Admin\Catalog\CatalogPackageData;
use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Support\Catalog\CatalogCollisionReporter;
use App\Support\Catalog\CatalogImageStorage;
use App\Support\Catalog\ManagedCatalogPath;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SaveCatalogPackage
{
    public function __construct(
        private readonly CatalogImageStorage $images,
        private readonly ManagedCatalogPath $managedPaths,
        private readonly CatalogCollisionReporter $collisions,
    ) {}

    public function create(CatalogPackageData $data): Service
    {
        $this->validateDraft($data);
        $this->ensureUniqueChoiceIdentities($data->variants, new Collection, 'variants');
        $this->ensureUniqueChoiceIdentities($data->extensions, new Collection, 'extensions');
        [$newCover, $newGallery, $newPaths] = $this->storeUploads($data);

        try {
            return DB::transaction(function () use ($data, $newCover, $newGallery, $newPaths): Service {
                $this->compensateUploadsAfterRollback($newPaths);
                $service = new Service;
                $this->fillPackage($service, $data, $newCover, $newGallery);
                $service->duration_minutes = null;
                $service->price_idr = null;
                $service->popularity = 0;
                $service->is_active = false;
                $service->published_at = null;
                $service->lock_version = 0;
                $service->save();

                $this->createChoices($service, ServiceVariant::class, $data->variants);
                $this->createChoices($service, ServiceExtension::class, $data->extensions);

                return $service;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->images->deleteManaged($newPaths);
            $this->collisions->report($exception, $data);
        } catch (Throwable $exception) {
            $this->images->deleteManaged($newPaths);
            throw $exception;
        }
    }

    public function update(Service $service, int $version, CatalogPackageData $data): Service
    {
        [$newCover, $newGallery, $newPaths] = $this->storeUploads($data);

        try {
            return DB::transaction(function () use (
                $service,
                $version,
                $data,
                $newCover,
                $newGallery,
                $newPaths,
            ): Service {
                $this->compensateUploadsAfterRollback($newPaths);
                $locked = Service::query()->whereKey($service->getKey())->lockForUpdate()->firstOrFail();
                $this->ensureFreshVersion($locked, $version);
                $this->ensureStablePackageSlug($locked, $data);

                $category = ServiceCategory::query()->whereKey($data->categoryId)->lockForUpdate()->firstOrFail();
                $existingVariants = ServiceVariant::query()->where('service_id', $locked->id)->lockForUpdate()->get();
                $existingExtensions = ServiceExtension::query()->where('service_id', $locked->id)->lockForUpdate()->get();
                $this->ensureOwnedChoiceIds($data->variants, $existingVariants, 'variants');
                $this->ensureOwnedChoiceIds($data->extensions, $existingExtensions, 'extensions');
                $this->ensureUniqueChoiceIdentities($data->variants, $existingVariants, 'variants');
                $this->ensureUniqueChoiceIdentities($data->extensions, $existingExtensions, 'extensions');

                $oldCover = $locked->image_path;
                $oldGallery = $locked->gallery_paths ?? [];
                $retainedGallery = $this->managedPaths->resolveRetained($oldGallery, $data->retainedGalleryIds);
                $nextCover = $newCover ?? ($data->removeCover ? null : $oldCover);
                $nextGallery = [...$retainedGallery, ...$newGallery];
                if (count($nextGallery) > 6) {
                    throw ValidationException::withMessages([
                        'galleryUploads' => __('validation.max.array', ['attribute' => 'galleryUploads', 'max' => 6]),
                    ]);
                }

                if ($locked->published_at !== null && $locked->is_active) {
                    ChangePackageLifecycle::validatePublishableData($category, $data, $nextCover, $nextGallery);
                }

                $this->fillPackage($locked, $data, $nextCover, $nextGallery);
                $this->reconcileChoices($locked, ServiceVariant::class, $existingVariants, $data->variants, 'service_variant_id');
                $this->reconcileChoices($locked, ServiceExtension::class, $existingExtensions, $data->extensions, 'service_extension_id');
                $locked->lock_version++;
                $locked->save();

                $removedPaths = array_values(array_unique([
                    ...($oldCover !== null && $oldCover !== $nextCover ? [$oldCover] : []),
                    ...array_diff($oldGallery, $nextGallery),
                ]));
                if ($removedPaths !== []) {
                    DB::afterCommit(fn () => $this->images->deleteManaged($removedPaths));
                }

                return $locked;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->images->deleteManaged($newPaths);
            $this->collisions->report($exception, $data, $service);
        } catch (Throwable $exception) {
            $this->images->deleteManaged($newPaths);
            throw $exception;
        }
    }

    public static function staleValidationMessage(): string
    {
        return app()->getLocale() === 'id'
            ? 'Paket katalog ini telah berubah. Muat ulang halaman lalu coba lagi.'
            : 'This catalog package has changed. Refresh the page and try again.';
    }

    private function validateDraft(CatalogPackageData $data): void
    {
        Validator::make([
            'categoryId' => $data->categoryId,
            'slug' => $data->slug,
            'nameId' => $data->nameId,
            'nameEn' => $data->nameEn,
            'displayOrder' => $data->displayOrder,
            'retainedGalleryIds' => $data->retainedGalleryIds,
        ], [
            'categoryId' => ['required', 'integer', 'exists:service_categories,id'],
            'slug' => ['required', 'string'],
            'nameId' => ['required', 'string'],
            'nameEn' => ['required', 'string'],
            'displayOrder' => ['integer', 'min:0'],
            'retainedGalleryIds' => ['size:0'],
        ])->validate();
    }

    /** @return array{0: ?string, 1: list<string>, 2: list<string>} */
    private function storeUploads(CatalogPackageData $data): array
    {
        $cover = null;
        $gallery = [];
        $paths = [];

        try {
            if ($data->cover !== null) {
                $cover = $this->images->store($data->cover, 'packages');
                $paths[] = $cover;
            }
            foreach ($data->galleryUploads as $upload) {
                $gallery[] = $this->images->store($upload, 'packages');
                $paths[] = $gallery[array_key_last($gallery)];
            }
        } catch (Throwable $exception) {
            $this->images->deleteManaged($paths);
            throw $exception;
        }

        return [$cover, $gallery, $paths];
    }

    /** @param list<string> $paths */
    private function compensateUploadsAfterRollback(array $paths): void
    {
        if ($paths !== []) {
            DB::afterRollBack(fn () => $this->images->deleteManaged($paths));
        }
    }

    /** @param list<string> $gallery */
    private function fillPackage(Service $service, CatalogPackageData $data, ?string $cover, array $gallery): void
    {
        $service->category_id = $data->categoryId;
        $service->slug = $data->slug;
        $service->name_id = $data->nameId;
        $service->name_en = $data->nameEn;
        $service->description_id = $data->descId;
        $service->description_en = $data->descEn;
        $service->long_description_id = $data->longId;
        $service->long_description_en = $data->longEn;
        $service->good_for_id = $data->benefitId;
        $service->good_for_en = $data->benefitEn;
        $service->is_vip = $data->isVip;
        $service->display_order = $data->displayOrder;
        $service->image_path = $cover;
        $service->gallery_paths = $gallery;
    }

    private function ensureFreshVersion(Service $service, int $version): void
    {
        if ($service->lock_version !== $version) {
            throw ValidationException::withMessages([
                'version' => self::staleValidationMessage(),
            ]);
        }
    }

    private function ensureStablePackageSlug(Service $service, CatalogPackageData $data): void
    {
        if ($service->published_at !== null && $service->slug !== $data->slug) {
            throw ValidationException::withMessages([
                'slug' => __('validation.in', ['attribute' => 'slug']),
            ]);
        }
    }

    /**
     * @param  list<CatalogChoiceData>  $choices
     * @param  Collection<int, Model>  $existing
     */
    private function ensureOwnedChoiceIds(array $choices, Collection $existing, string $field): void
    {
        $owned = $existing->keyBy('id');
        $seen = [];
        foreach ($choices as $index => $choice) {
            if ($choice->id === null) {
                continue;
            }
            if (isset($seen[$choice->id]) || ! $owned->has($choice->id)) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.id" => __('validation.exists', ['attribute' => 'id']),
                ]);
            }
            $seen[$choice->id] = true;
        }
    }

    /**
     * @param  list<CatalogChoiceData>  $choices
     * @param  Collection<int, ServiceVariant|ServiceExtension>  $existing
     */
    private function ensureUniqueChoiceIdentities(array $choices, Collection $existing, string $field): void
    {
        $submittedIdentities = [];

        foreach ($choices as $index => $choice) {
            $identity = $choice->mode->value.':'.$choice->durationMinutes;
            $conflict = $existing->contains(
                fn (ServiceVariant|ServiceExtension $current): bool => $current->id !== $choice->id
                    && $current->treatment_mode === $choice->mode
                    && $current->duration_minutes === $choice->durationMinutes,
            );

            if ($conflict || isset($submittedIdentities[$identity])) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.durationMinutes" => __('validation.unique', ['attribute' => 'durationMinutes']),
                ]);
            }

            $submittedIdentities[$identity] = true;
        }
    }

    /** @param class-string<ServiceVariant|ServiceExtension> $model
     * @param  list<CatalogChoiceData>  $choices
     */
    private function createChoices(Service $service, string $model, array $choices): void
    {
        foreach ($choices as $choice) {
            $row = new $model;
            $row->service_id = $service->id;
            $this->fillChoice($row, $choice);
            $row->published_at = null;
            $row->save();
        }
    }

    /**
     * @param  class-string<ServiceVariant|ServiceExtension>  $model
     * @param  Collection<int, ServiceVariant|ServiceExtension>  $existing
     * @param  list<CatalogChoiceData>  $submitted
     */
    private function reconcileChoices(
        Service $service,
        string $model,
        Collection $existing,
        array $submitted,
        string $bookingForeignKey,
    ): void {
        $byId = $existing->keyBy('id');
        $submittedIds = collect($submitted)->pluck('id')->filter()->mapWithKeys(fn (int $id): array => [$id => true]);

        foreach ($existing as $choice) {
            if ($submittedIds->has($choice->id)) {
                continue;
            }

            $referenced = Booking::query()->where($bookingForeignKey, $choice->id)->exists();
            if ($service->published_at === null && $choice->published_at === null && ! $referenced) {
                $choice->delete();
            } else {
                $choice->is_active = false;
                $choice->save();
            }
        }

        foreach ($submitted as $index => $data) {
            $choice = $data->id === null ? new $model : $byId->get($data->id);
            if (! $choice instanceof $model) {
                throw ValidationException::withMessages([
                    "{$bookingForeignKey}.{$index}.id" => __('validation.exists', ['attribute' => 'id']),
                ]);
            }
            if ($choice->exists && $choice->published_at !== null && $choice->code !== $data->code) {
                $field = $model === ServiceVariant::class ? 'variants' : 'extensions';
                throw ValidationException::withMessages([
                    "{$field}.{$index}.code" => __('validation.in', ['attribute' => 'code']),
                ]);
            }

            $choice->service_id = $service->id;
            $this->fillChoice($choice, $data);
            if ($service->published_at !== null && $service->is_active && $data->isActive && $choice->published_at === null) {
                $choice->published_at = now();
            }
            $choice->save();
        }
    }

    private function fillChoice(ServiceVariant|ServiceExtension $choice, CatalogChoiceData $data): void
    {
        $choice->code = $data->code;
        $choice->treatment_mode = $data->mode;
        $choice->duration_minutes = $data->durationMinutes;
        $choice->price_idr = $data->priceIdr;
        $choice->is_active = $data->isActive;
        $choice->display_order = $data->displayOrder;
    }
}
