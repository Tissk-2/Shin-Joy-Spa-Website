<?php

namespace App\Actions\Catalog;

use App\Data\Admin\Catalog\CatalogPackageData;
use App\Enums\CatalogStatus;
use App\Enums\TreatmentMode;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ChangePackageLifecycle
{
    public function __invoke(Service $service, int $version, CatalogStatus $target): Service
    {
        return DB::transaction(function () use ($service, $version, $target): Service {
            $locked = $this->lock($service, $version);
            $current = $locked->catalogStatus();

            if ($target === CatalogStatus::Published && $current === CatalogStatus::Draft) {
                $this->validatePublishable($locked);
                $publishedAt = now();
                $locked->published_at = $publishedAt;
                $locked->is_active = true;
                $this->publishActiveChildren($locked, $publishedAt);
            } elseif ($target === CatalogStatus::Archived && $current === CatalogStatus::Published) {
                $locked->is_active = false;
            } else {
                $this->invalidTransition();
            }

            $locked->lock_version++;
            $locked->save();

            return $locked;
        });
    }

    public function restore(Service $service, int $version): Service
    {
        return DB::transaction(function () use ($service, $version): Service {
            $locked = $this->lock($service, $version);
            if ($locked->catalogStatus() !== CatalogStatus::Archived) {
                $this->invalidTransition();
            }

            $this->validatePublishable($locked);
            $locked->is_active = true;
            $this->publishActiveChildren($locked, now());
            $locked->lock_version++;
            $locked->save();

            return $locked;
        });
    }

    public static function validatePublishableData(
        ServiceCategory $category,
        CatalogPackageData $data,
        ?string $cover,
        array $gallery,
    ): void {
        self::validateValues(
            $category,
            [
                'nameId' => $data->nameId,
                'nameEn' => $data->nameEn,
                'descId' => $data->descId,
                'descEn' => $data->descEn,
                'longId' => $data->longId,
                'longEn' => $data->longEn,
                'benefitId' => $data->benefitId,
                'benefitEn' => $data->benefitEn,
                'cover' => $cover,
                'gallery' => $gallery,
                'displayOrder' => $data->displayOrder,
            ],
            $data->variants,
            $data->extensions,
        );
    }

    private function lock(Service $service, int $version): Service
    {
        $locked = Service::query()->whereKey($service->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->lock_version !== $version) {
            throw ValidationException::withMessages([
                'version' => SaveCatalogPackage::staleValidationMessage(),
            ]);
        }

        $locked->setRelation('category', ServiceCategory::query()
            ->whereKey($locked->category_id)
            ->lockForUpdate()
            ->firstOrFail());
        $locked->load([
            'variants' => fn ($query) => $query->lockForUpdate(),
            'extensions' => fn ($query) => $query->lockForUpdate(),
        ]);

        return $locked;
    }

    private function validatePublishable(Service $service): void
    {
        self::validateValues(
            $service->category,
            [
                'nameId' => $service->name_id,
                'nameEn' => $service->name_en,
                'descId' => $service->description_id,
                'descEn' => $service->description_en,
                'longId' => $service->long_description_id,
                'longEn' => $service->long_description_en,
                'benefitId' => $service->good_for_id,
                'benefitEn' => $service->good_for_en,
                'cover' => $service->image_path,
                'gallery' => $service->gallery_paths ?? [],
                'displayOrder' => $service->display_order,
            ],
            $service->variants->all(),
            $service->extensions->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  iterable<int, mixed>  $variants
     * @param  iterable<int, mixed>  $extensions
     */
    private static function validateValues(
        ServiceCategory $category,
        array $package,
        iterable $variants,
        iterable $extensions,
    ): void {
        $variantRows = self::choiceRows($variants);
        $extensionRows = self::choiceRows($extensions);
        $validator = Validator::make([
            'categoryId' => $category->id,
            'categoryPublished' => $category->published_at !== null,
            'categoryActive' => (bool) $category->is_active,
            ...$package,
            'variants' => $variantRows,
            'extensions' => $extensionRows,
        ], [
            'nameId' => ['required', 'string', 'max:255'],
            'nameEn' => ['required', 'string', 'max:255'],
            'descId' => ['required', 'string'],
            'descEn' => ['required', 'string'],
            'longId' => ['required', 'string'],
            'longEn' => ['required', 'string'],
            'benefitId' => ['nullable', 'string', 'required_with:benefitEn'],
            'benefitEn' => ['nullable', 'string', 'required_with:benefitId'],
            'cover' => ['required', 'string'],
            'gallery' => ['array', 'max:6'],
            'displayOrder' => ['integer', 'min:0'],
            'variants' => ['array'],
            'variants.*.code' => ['required', 'string', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'variants.*.mode' => ['required', Rule::enum(TreatmentMode::class)],
            'variants.*.durationMinutes' => ['required', 'integer', 'min:1'],
            'variants.*.priceIdr' => ['required', 'integer', 'min:1'],
            'variants.*.displayOrder' => ['required', 'integer', 'min:0'],
            'extensions' => ['array'],
            'extensions.*.code' => ['required', 'string', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'extensions.*.mode' => ['required', Rule::enum(TreatmentMode::class)],
            'extensions.*.durationMinutes' => ['required', 'integer', 'min:1'],
            'extensions.*.priceIdr' => ['required', 'integer', 'min:1'],
            'extensions.*.displayOrder' => ['required', 'integer', 'min:0'],
        ]);

        $validator->after(function ($validator) use ($category, $variantRows, $extensionRows): void {
            if ($category->published_at === null || ! $category->is_active) {
                $validator->errors()->add('categoryId', __('validation.exists', ['attribute' => 'categoryId']));
            }

            $activeVariants = array_values(array_filter($variantRows, fn (array $row): bool => $row['isActive']));
            if ($activeVariants === []) {
                $validator->errors()->add('variants', __('validation.required', ['attribute' => 'variants']));
            }

            $seenVariants = [];
            foreach ($activeVariants as $index => $row) {
                $key = $row['mode'].':'.$row['durationMinutes'];
                if (isset($seenVariants[$key])) {
                    $validator->errors()->add("variants.{$index}.durationMinutes", __('validation.unique', ['attribute' => 'durationMinutes']));
                }
                $seenVariants[$key] = true;
            }

            $seenModes = [];
            foreach (array_values(array_filter($extensionRows, fn (array $row): bool => $row['isActive'])) as $index => $row) {
                if (isset($seenModes[$row['mode']])) {
                    $validator->errors()->add("extensions.{$index}.mode", __('validation.unique', ['attribute' => 'mode']));
                }
                $seenModes[$row['mode']] = true;
            }
        });
        $validator->validate();
    }

    /** @param iterable<int, mixed> $choices
     * @return list<array<string, mixed>>
     */
    private static function choiceRows(iterable $choices): array
    {
        $rows = [];
        foreach ($choices as $choice) {
            $rows[] = [
                'code' => $choice->code,
                'mode' => $choice->mode instanceof TreatmentMode ? $choice->mode->value : $choice->treatment_mode->value,
                'durationMinutes' => $choice->durationMinutes ?? $choice->duration_minutes,
                'priceIdr' => $choice->priceIdr ?? $choice->price_idr,
                'isActive' => $choice->isActive ?? $choice->is_active,
                'displayOrder' => $choice->displayOrder ?? $choice->display_order,
            ];
        }

        return $rows;
    }

    private function publishActiveChildren(Service $service, mixed $publishedAt): void
    {
        foreach ([$service->variants, $service->extensions] as $choices) {
            foreach ($choices as $choice) {
                if ($choice->is_active && $choice->published_at === null) {
                    $choice->published_at = $publishedAt;
                    $choice->save();
                }
            }
        }
    }

    private function invalidTransition(): never
    {
        throw ValidationException::withMessages([
            'status' => __('validation.in', ['attribute' => 'status']),
        ]);
    }
}
