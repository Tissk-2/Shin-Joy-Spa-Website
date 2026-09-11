<?php

namespace App\Http\Requests\Admin;

use App\Models\ServiceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CatalogLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');
        $ability = match ($this->route()?->getName()) {
            'admin.catalog.categories.publish' => 'publish',
            'admin.catalog.categories.archive' => 'archive',
            'admin.catalog.categories.restore' => 'restore',
            'admin.catalog.categories.destroy' => 'delete',
            default => null,
        };

        return $ability !== null
            && $category instanceof ServiceCategory
            && $this->user()?->can($ability, $category) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['version']) as $field) {
                $validator->errors()->add($field, __('admin.status.unsupported_field'));
            }
        }];
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }
}
