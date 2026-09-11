<?php

namespace App\Http\Requests\Admin;

use App\Data\Admin\Catalog\CatalogCategoryData;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

final class StoreCatalogCategoryRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'version',
        'slug',
        'nameId',
        'nameEn',
        'displayOrder',
        'image',
        'removeImage',
    ];

    protected function prepareForValidation(): void
    {
        $this->merge($this->trimmedStrings(['slug', 'nameId', 'nameEn']));
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', ServiceCategory::class) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'version' => ['prohibited'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique('service_categories', 'slug'),
            ],
            'nameId' => ['required', 'string', 'max:255'],
            'nameEn' => ['required', 'string', 'max:255'],
            'displayOrder' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', $this->imageRule()],
            'removeImage' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [$this->rejectUnknownFields(...)];
    }

    public function toData(): CatalogCategoryData
    {
        $validated = $this->validated();

        return new CatalogCategoryData(
            slug: $validated['slug'],
            nameId: $validated['nameId'],
            nameEn: $validated['nameEn'],
            displayOrder: (int) $validated['displayOrder'],
            image: $validated['image'] ?? null,
            removeImage: (bool) ($validated['removeImage'] ?? false),
        );
    }

    /** @param list<string> $fields
     * @return array<string, mixed>
     */
    private function trimmedStrings(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);
            $normalized[$field] = is_string($value) ? trim($value) : $value;
        }

        return $normalized;
    }

    private function imageRule(): File
    {
        return File::image()
            ->types(['jpg', 'jpeg', 'png', 'webp'])
            ->max(5 * 1024)
            ->dimensions(
                Rule::dimensions()
                    ->minWidth(600)
                    ->minHeight(400)
                    ->maxWidth(6000)
                    ->maxHeight(6000),
            );
    }

    private function rejectUnknownFields(Validator $validator): void
    {
        foreach (array_diff(array_keys($this->all()), self::ALLOWED_FIELDS) as $field) {
            $validator->errors()->add($field, __('admin.status.unsupported_field'));
        }
    }
}
