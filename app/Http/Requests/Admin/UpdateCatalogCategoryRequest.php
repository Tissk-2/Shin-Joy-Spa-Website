<?php

namespace App\Http\Requests\Admin;

use App\Data\Admin\Catalog\CatalogCategoryData;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

final class UpdateCatalogCategoryRequest extends FormRequest
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
        $category = $this->route('category');

        return $category instanceof ServiceCategory
            && $this->user()?->can('update', $category) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'version' => ['required', 'integer', 'min:0'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique('service_categories', 'slug')->ignore(
                    $category instanceof ServiceCategory ? $category->getKey() : null,
                ),
            ],
            'nameId' => ['present', 'nullable', 'string', 'max:255'],
            'nameEn' => ['present', 'nullable', 'string', 'max:255'],
            'displayOrder' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', $this->imageRule()],
            'removeImage' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [...self::ALLOWED_FIELDS, '_method'];

            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, __('admin.status.unsupported_field'));
            }

            $category = $this->route('category');
            if ($category instanceof ServiceCategory
                && $category->published_at !== null
                && is_string($this->input('slug'))
                && $this->input('slug') !== $category->slug) {
                $validator->errors()->add(
                    'slug',
                    __('validation.in', ['attribute' => 'slug']),
                );
            }
        }];
    }

    public function toData(): CatalogCategoryData
    {
        $validated = $this->validated();

        return new CatalogCategoryData(
            slug: $validated['slug'],
            nameId: (string) ($validated['nameId'] ?? ''),
            nameEn: (string) ($validated['nameEn'] ?? ''),
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
}
