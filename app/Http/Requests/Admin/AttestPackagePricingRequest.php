<?php

namespace App\Http\Requests\Admin;

use App\Enums\PricingBasis;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class AttestPackagePricingRequest extends FormRequest
{
    /** @var list<string> */
    private const FIELDS = ['version', 'pricingBasis', 'minGuests', 'maxGuests'];

    public function authorize(): bool
    {
        $service = $this->route('service');

        return $service instanceof Service
            && $this->user()?->can('attestPricing', $service) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:0'],
            'pricingBasis' => ['required', Rule::enum(PricingBasis::class)],
            'minGuests' => ['required', 'integer', 'min:1', 'max:4'],
            'maxGuests' => ['required', 'integer', 'min:1', 'max:4'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), self::FIELDS) as $field) {
                $validator->errors()->add($field, __('admin.status.unsupported_field'));
            }

            $min = $this->input('minGuests');
            $max = $this->input('maxGuests');
            if (is_numeric($min) && is_numeric($max) && (int) $min > (int) $max) {
                $validator->errors()->add('maxGuests', __('validation.booking.guest_coverage'));
            }
        }];
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }

    public function basis(): PricingBasis
    {
        return PricingBasis::from($this->validated('pricingBasis'));
    }

    public function minGuests(): int
    {
        return (int) $this->validated('minGuests');
    }

    public function maxGuests(): int
    {
        return (int) $this->validated('maxGuests');
    }
}
