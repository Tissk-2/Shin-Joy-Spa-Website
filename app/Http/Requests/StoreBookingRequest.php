<?php

namespace App\Http\Requests;

use App\Enums\TreatmentMode;
use App\Models\Booking;
use App\Support\CustomerIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'bail',
                'required',
                'string',
                'max:120',
                fn (string $attribute, mixed $value, callable $fail) => $this->validateNotBlank($attribute, $value, $fail),
            ],
            'phone' => [
                'bail',
                'nullable',
                'string',
                'max:32',
                'regex:/\A[+\d][\d\s-]{7,}\z/',
                fn (string $attribute, mixed $value, callable $fail) => $this->validatePhoneDigits($attribute, $value, $fail),
            ],
            'service' => ['bail', 'required', 'string', 'max:80'],
            'mode' => [
                'bail',
                'required',
                'string',
                Rule::in(array_map(fn (TreatmentMode $mode): string => $mode->value, TreatmentMode::cases())),
            ],
            'duration' => ['bail', 'required', 'integer', 'min:1', 'max:480'],
            'extension' => ['bail', 'nullable', 'string', 'max:80'],
            'date' => [
                'bail',
                'required',
                'string',
                'date_format:Y-m-d',
                fn (string $attribute, mixed $value, callable $fail) => $this->validateTodayOrFutureDate($attribute, $value, $fail),
            ],
            'time' => ['bail', 'required', 'string', 'date_format:H:i', Rule::in(self::appointmentTimes())],
            'guests' => ['bail', 'required', 'integer', 'min:1', 'max:4'],
            'notes' => ['bail', 'nullable', 'string', 'max:1000'],
            'submission_key' => ['bail', 'required', 'uuid'],
            'expected_total_price_idr' => ['bail', 'required', 'integer', 'min:0'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('date') || $validator->errors()->has('time')) {
                return;
            }

            $date = $this->input('date');
            $time = $this->input('time');
            if (! is_string($date) || ! is_string($time)) {
                return;
            }

            $appointment = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, 'Asia/Jakarta');
            if ($appointment !== false
                && $appointment->lessThanOrEqualTo(CarbonImmutable::now('Asia/Jakarta'))
                && ! $this->hasExistingSubmission()) {
                $validator->errors()->add('time', __('validation.booking.future_time'));
            }
        }];
    }

    /**
     * @return array{
     *     name: string,
     *     phone: string,
     *     service: string,
     *     mode: string,
     *     duration: int,
     *     extension: string|null,
     *     appointment_date: string,
     *     appointment_time: string,
     *     guests: int,
     *     notes: string|null,
     *     submission_key: string,
     *     expected_total_price_idr: int
     * }
     */
    public function validatedBookingData(): array
    {
        return self::validatedBookingDataFrom($this->validated());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     name: string,
     *     phone: string,
     *     service: string,
     *     mode: string,
     *     duration: int,
     *     extension: string|null,
     *     appointment_date: string,
     *     appointment_time: string,
     *     guests: int,
     *     notes: string|null,
     *     submission_key: string,
     *     expected_total_price_idr: int
     * }
     */
    public static function validatedBookingDataFrom(array $data): array
    {
        $extension = isset($data['extension']) && is_string($data['extension'])
            ? trim($data['extension'])
            : null;
        $notes = isset($data['notes']) && is_string($data['notes'])
            ? trim($data['notes'])
            : null;

        return [
            'name' => CustomerIdentity::name((string) $data['name']),
            'phone' => CustomerIdentity::phone((string) ($data['phone'] ?? '')),
            'service' => (string) $data['service'],
            'mode' => (string) $data['mode'],
            'duration' => (int) $data['duration'],
            'extension' => $extension === '' ? null : $extension,
            'appointment_date' => (string) $data['date'],
            'appointment_time' => (string) $data['time'],
            'guests' => (int) $data['guests'],
            'notes' => $notes === '' ? null : $notes,
            'submission_key' => (string) $data['submission_key'],
            'expected_total_price_idr' => (int) ($data['expected_total_price_idr'] ?? 0),
        ];
    }

    /**
     * @return list<string>
     */
    public static function appointmentTimes(): array
    {
        $times = [];
        $time = CarbonImmutable::create(2026, 1, 1, 9, 0, 0, 'Asia/Jakarta');
        $last = CarbonImmutable::create(2026, 1, 1, 20, 0, 0, 'Asia/Jakarta');

        while ($time <= $last) {
            $times[] = $time->format('H:i');
            $time = $time->addMinutes(30);
        }

        return $times;
    }

    private function validateNotBlank(string $attribute, mixed $value, callable $fail): void
    {
        if (is_string($value) && CustomerIdentity::name($value) !== '') {
            return;
        }

        $fail(__('validation.required', ['attribute' => $attribute]));
    }

    private function validatePhoneDigits(string $attribute, mixed $value, callable $fail): void
    {
        if (is_string($value) && strlen(preg_replace('/\D+/', '', $value) ?? '') >= 8) {
            return;
        }

        $fail(__('validation.regex', ['attribute' => $attribute]));
    }

    private function validateTodayOrFutureDate(string $attribute, mixed $value, callable $fail): void
    {
        if (! is_string($value)) {
            $fail(__('validation.date_format', ['attribute' => $attribute, 'format' => 'Y-m-d']));

            return;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Jakarta');

        if ($date === false) {
            $fail(__('validation.date_format', ['attribute' => $attribute, 'format' => 'Y-m-d']));

            return;
        }

        if ($date->lt(CarbonImmutable::now('Asia/Jakarta')->startOfDay()) && ! $this->hasExistingSubmission()) {
            $fail(__('validation.after_or_equal', ['attribute' => $attribute, 'date' => __('validation.booking.today')]));
        }
    }

    private function hasExistingSubmission(): bool
    {
        $key = $this->input('submission_key');
        $user = $this->user();

        return $user !== null
            && is_string($key)
            && Str::isUuid($key)
            && Booking::query()->where('user_id', $user->id)->where('submission_key', $key)->exists();
    }
}
