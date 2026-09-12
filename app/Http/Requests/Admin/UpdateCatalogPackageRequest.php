<?php

namespace App\Http\Requests\Admin;

use App\Data\Admin\Catalog\CatalogChoiceData;
use App\Data\Admin\Catalog\CatalogPackageData;
use App\Enums\TreatmentMode;
use App\Models\Service;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Support\Catalog\ManagedCatalogPath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateCatalogPackageRequest extends FormRequest
{
    /** @var list<string> */
    private const TOP_LEVEL_FIELDS = [
        'version', 'categoryId', 'slug', 'nameId', 'nameEn', 'descId', 'descEn',
        'longId', 'longEn', 'benefitId', 'benefitEn', 'isVip', 'displayOrder',
        'cover', 'removeCover', 'retainedGalleryIds', 'galleryUploads', 'variants',
        'extensions',
    ];

    /** @var list<string> */
    private const CHOICE_FIELDS = [
        'id', 'code', 'mode', 'durationMinutes', 'priceIdr', 'isActive', 'displayOrder',
    ];

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['slug', 'nameId', 'nameEn', 'descId', 'descEn', 'longId', 'longEn', 'benefitId', 'benefitEn'] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '' && in_array($field, ['descId', 'descEn', 'longId', 'longEn', 'benefitId', 'benefitEn'], true)) {
                    $value = null;
                }
            }
            $normalized[$field] = $value;
        }

        foreach (['variants', 'extensions'] as $collection) {
            $rows = $this->input($collection);
            if ($rows === '[]') {
                $normalized[$collection] = [];

                continue;
            }
            if (! is_array($rows)) {
                continue;
            }

            $normalized[$collection] = array_map(static function (mixed $row): mixed {
                if (! is_array($row)) {
                    return $row;
                }

                foreach (['code', 'mode'] as $field) {
                    if (array_key_exists($field, $row) && is_string($row[$field])) {
                        $row[$field] = trim($row[$field]);
                    }
                }

                return $row;
            }, $rows);
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        $service = $this->route('service');

        return $service instanceof Service
            && $this->user()?->can('update', $service) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $service = $this->route('service');

        return [
            'version' => [$this->isCreate() ? 'prohibited' : 'required', 'integer', 'min:0'],
            'categoryId' => ['required', 'integer', Rule::exists('service_categories', 'id')],
            'slug' => [
                'required', 'string', 'max:255',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique('services', 'slug')->ignore($service instanceof Service ? $service->getKey() : null),
            ],
            'nameId' => ['required', 'string', 'max:255'],
            'nameEn' => ['required', 'string', 'max:255'],
            'descId' => ['present', 'nullable', 'string', 'max:10000'],
            'descEn' => ['present', 'nullable', 'string', 'max:10000'],
            'longId' => ['present', 'nullable', 'string', 'max:20000'],
            'longEn' => ['present', 'nullable', 'string', 'max:20000'],
            'benefitId' => ['present', 'nullable', 'string', 'max:255'],
            'benefitEn' => ['present', 'nullable', 'string', 'max:255'],
            'isVip' => ['required', 'boolean'],
            'displayOrder' => ['required', 'integer', 'min:0'],
            'cover' => ['nullable', $this->imageRule()],
            'removeCover' => ['sometimes', 'boolean'],
            'retainedGalleryIds' => ['sometimes', 'array', 'max:6'],
            'retainedGalleryIds.*' => ['string', 'size:64', 'regex:/\A[0-9a-f]{64}\z/'],
            'galleryUploads' => ['sometimes', 'array', 'max:6'],
            'galleryUploads.*' => ['required', $this->imageRule()],
            'variants' => ['present', 'array'],
            'extensions' => ['present', 'array'],
            ...$this->choiceRules('variants'),
            ...$this->choiceRules('extensions'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = $this->isCreate() ? self::TOP_LEVEL_FIELDS : [...self::TOP_LEVEL_FIELDS, '_method'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, __('admin.status.unsupported_field'));
            }
            if ($this->isCreate() && array_key_exists('version', $this->all())) {
                $validator->errors()->add('version', __('validation.prohibited', ['attribute' => 'version']));
            }

            $this->rejectUnknownChoiceFields($validator, 'variants');
            $this->rejectUnknownChoiceFields($validator, 'extensions');
            $this->validateBenefitPair($validator);
            $this->validateGallery($validator);
            $this->validateChoices($validator, 'variants', ServiceVariant::class);
            $this->validateChoices($validator, 'extensions', ServiceExtension::class);
            $this->validatePublishedIdentifiers($validator);
        }];
    }

    public function toData(): CatalogPackageData
    {
        $validated = $this->validated();

        return new CatalogPackageData(
            categoryId: (int) $validated['categoryId'],
            slug: $validated['slug'],
            nameId: $validated['nameId'],
            nameEn: $validated['nameEn'],
            descId: $validated['descId'],
            descEn: $validated['descEn'],
            longId: $validated['longId'],
            longEn: $validated['longEn'],
            benefitId: $validated['benefitId'],
            benefitEn: $validated['benefitEn'],
            isVip: (bool) $validated['isVip'],
            displayOrder: (int) $validated['displayOrder'],
            cover: $validated['cover'] ?? null,
            removeCover: (bool) ($validated['removeCover'] ?? false),
            retainedGalleryIds: array_values($validated['retainedGalleryIds'] ?? []),
            galleryUploads: array_values($validated['galleryUploads'] ?? []),
            variants: $this->choiceData($validated['variants']),
            extensions: $this->choiceData($validated['extensions']),
        );
    }

    protected function isCreate(): bool
    {
        return false;
    }

    /** @return array<string, list<mixed>> */
    private function choiceRules(string $field): array
    {
        return [
            "{$field}.*" => ['array'],
            "{$field}.*.id" => [$this->isCreate() ? 'prohibited' : 'sometimes', 'nullable', 'integer', 'min:1'],
            "{$field}.*.code" => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            "{$field}.*.mode" => ['required', Rule::enum(TreatmentMode::class)],
            "{$field}.*.durationMinutes" => ['required', 'integer', 'min:1'],
            "{$field}.*.priceIdr" => ['required', 'integer', 'min:1'],
            "{$field}.*.isActive" => ['required', 'boolean'],
            "{$field}.*.displayOrder" => ['required', 'integer', 'min:0'],
        ];
    }

    private function imageRule(): File
    {
        return File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(5 * 1024)->dimensions(
            Rule::dimensions()->minWidth(600)->minHeight(400)->maxWidth(6000)->maxHeight(6000),
        );
    }

    private function rejectUnknownChoiceFields(Validator $validator, string $field): void
    {
        $rows = $this->input($field, []);
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (array_diff(array_keys($row), self::CHOICE_FIELDS) as $unknown) {
                $validator->errors()->add("{$field}.{$index}.{$unknown}", __('admin.status.unsupported_field'));
            }
            if ($this->isCreate() && array_key_exists('id', $row)) {
                $validator->errors()->add("{$field}.{$index}.id", __('validation.prohibited', ['attribute' => 'id']));
            }
        }
    }

    private function validateBenefitPair(Validator $validator): void
    {
        if (($this->input('benefitId') === null) !== ($this->input('benefitEn') === null)) {
            $validator->errors()->add('benefitEn', __('validation.required', ['attribute' => 'benefitEn']));
        }
    }

    private function validateGallery(Validator $validator): void
    {
        $retained = $this->input('retainedGalleryIds', []);
        $uploads = $this->file('galleryUploads', []);
        if (is_array($retained) && is_array($uploads) && count($retained) + count($uploads) > 6) {
            $validator->errors()->add('galleryUploads', __('validation.max.array', ['attribute' => 'galleryUploads', 'max' => 6]));
        }

        $service = $this->route('service');
        if (! $this->isCreate() && $service instanceof Service && is_array($retained)) {
            try {
                app(ManagedCatalogPath::class)->resolveRetained($service->gallery_paths ?? [], $retained);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        } elseif ($this->isCreate() && is_array($retained) && $retained !== []) {
            $validator->errors()->add('retainedGalleryIds', __('admin.catalog.validation.gallery_reference'));
        }
    }

    /** @param class-string<ServiceVariant|ServiceExtension> $model */
    private function validateChoices(Validator $validator, string $field, string $model): void
    {
        $rows = $this->input($field, []);
        if (! is_array($rows)) {
            return;
        }

        $service = $this->route('service');
        $owned = $service instanceof Service
            ? $model::query()->where('service_id', $service->id)->get()->keyBy('id')
            : collect();
        $ids = [];
        $codes = [];
        $activeChoices = [];
        $activeModes = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
            if ($id !== null) {
                if (isset($ids[$id]) || ! $owned->has($id)) {
                    $validator->errors()->add("{$field}.{$index}.id", __('validation.exists', ['attribute' => 'id']));
                }
                $ids[$id] = true;
            }

            $code = $row['code'] ?? null;
            if (is_string($code)) {
                if (isset($codes[$code])) {
                    $validator->errors()->add("{$field}.{$index}.code", __('validation.unique', ['attribute' => 'code']));
                }
                $codes[$code] = true;
            }

            if (! $this->booleanValue($row['isActive'] ?? null)) {
                continue;
            }

            $mode = $row['mode'] ?? null;
            $duration = filter_var($row['durationMinutes'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
            if (is_string($mode) && $duration !== null) {
                $choice = $mode.':'.$duration;
                if (isset($activeChoices[$choice])) {
                    $validator->errors()->add("{$field}.{$index}.durationMinutes", __('validation.unique', ['attribute' => 'durationMinutes']));
                }
                $activeChoices[$choice] = true;

                if ($field === 'extensions') {
                    if (isset($activeModes[$mode])) {
                        $validator->errors()->add("{$field}.{$index}.mode", __('validation.unique', ['attribute' => 'mode']));
                    }
                    $activeModes[$mode] = true;
                }
            }
        }
    }

    private function validatePublishedIdentifiers(Validator $validator): void
    {
        $service = $this->route('service');
        if (! $service instanceof Service) {
            return;
        }

        if ($service->published_at !== null && is_string($this->input('slug')) && $this->input('slug') !== $service->slug) {
            $validator->errors()->add('slug', __('validation.in', ['attribute' => 'slug']));
        }

        foreach ([['variants', ServiceVariant::class], ['extensions', ServiceExtension::class]] as [$field, $model]) {
            $published = $model::query()->where('service_id', $service->id)->whereNotNull('published_at')->get()->keyBy('id');
            $rows = $this->input($field, []);
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $index => $row) {
                if (! is_array($row) || ! isset($row['id']) || ! $published->has((int) $row['id'])) {
                    continue;
                }
                if (($row['code'] ?? null) !== $published[(int) $row['id']]->code) {
                    $validator->errors()->add("{$field}.{$index}.code", __('validation.in', ['attribute' => 'code']));
                }
            }
        }
    }

    private function booleanValue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<CatalogChoiceData>
     */
    private function choiceData(array $rows): array
    {
        return array_map(static fn (array $row): CatalogChoiceData => new CatalogChoiceData(
            id: isset($row['id']) ? (int) $row['id'] : null,
            code: $row['code'],
            mode: TreatmentMode::from($row['mode']),
            durationMinutes: (int) $row['durationMinutes'],
            priceIdr: (int) $row['priceIdr'],
            isActive: (bool) $row['isActive'],
            displayOrder: (int) $row['displayOrder'],
        ), array_values($rows));
    }
}
