<?php

namespace App\Http\Requests\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class AdminBookingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Booking::class) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', Rule::in(['upcoming', 'past', 'all'])],
            'status' => ['sometimes', 'nullable', Rule::enum(BookingStatus::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'dateFrom' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'dateTo' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
                ...($this->filled('dateFrom') ? ['after_or_equal:dateFrom'] : []),
            ],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $unknownFields = array_diff(
                array_keys($this->query->all()),
                array_keys($validator->getRules()),
            );

            foreach ($unknownFields as $field) {
                $validator->errors()->add((string) $field, trans('validation.prohibited', [
                    'attribute' => str_replace('_', ' ', (string) $field),
                ]));
            }
        }];
    }

    /**
     * @return array{scope: string, status: ?string, q: string, dateFrom: ?string, dateTo: ?string}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'scope' => $validated['scope'] ?? 'upcoming',
            'status' => $validated['status'] ?? null,
            'q' => trim((string) ($validated['q'] ?? '')),
            'dateFrom' => $validated['dateFrom'] ?? null,
            'dateTo' => $validated['dateTo'] ?? null,
        ];
    }
}
