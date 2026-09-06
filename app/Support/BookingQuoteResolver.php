<?php

namespace App\Support;

use App\Models\Service;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class BookingQuoteResolver
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function resolve(User $user, array $data): ResolvedBookingQuote
    {
        $service = Service::query()
            ->publiclyVisible()
            ->with([
                'variants' => fn ($query) => $query->active(),
                'extensions' => fn ($query) => $query->active(),
            ])
            ->where('slug', $data['service'])
            ->first();

        if (! $service instanceof Service) {
            throw ValidationException::withMessages([
                'service' => __('validation.exists', ['attribute' => 'service']),
            ]);
        }

        $variant = $service->variants->first(
            fn (ServiceVariant $candidate): bool => $candidate->treatment_mode->value === $data['mode']
                && $candidate->duration_minutes === $data['duration'],
        );

        if (! $variant instanceof ServiceVariant) {
            throw ValidationException::withMessages([
                'duration' => __('validation.exists', ['attribute' => 'duration']),
            ]);
        }

        $extension = $this->resolveExtension($service, $variant, $data['extension'] ?? null);

        return new ResolvedBookingQuote([
            'service_id' => $service->id,
            'service_variant_id' => $variant->id,
            'service_extension_id' => $extension?->id,
            'customer_name' => CustomerIdentity::name((string) $data['name']),
            'customer_email' => CustomerIdentity::email($user->email),
            'customer_phone' => CustomerIdentity::phone((string) $data['phone']),
            'appointment_date' => (string) $data['appointment_date'],
            'appointment_time' => (string) $data['appointment_time'],
            'appointment_timezone' => 'Asia/Jakarta',
            'guests' => (int) $data['guests'],
            'notes' => $data['notes'] ?? null,
            'package_slug' => $service->slug,
            'package_name_id' => $service->name_id,
            'package_name_en' => $service->name_en,
            'package_is_vip' => $service->is_vip,
            'treatment_mode' => $variant->treatment_mode->value,
            'base_choice_code' => $variant->code,
            'base_duration_minutes' => $variant->duration_minutes,
            'base_price_idr' => $variant->price_idr,
            'extension_choice_code' => $extension?->code,
            'extension_duration_minutes' => $extension?->duration_minutes,
            'extension_price_idr' => $extension?->price_idr,
            'total_duration_minutes' => $variant->duration_minutes + ($extension?->duration_minutes ?? 0),
            'total_price_idr' => $variant->price_idr + ($extension?->price_idr ?? 0),
        ]);
    }

    private function resolveExtension(Service $service, ServiceVariant $variant, mixed $extensionCode): ?ServiceExtension
    {
        if ($extensionCode === null || $extensionCode === '') {
            return null;
        }

        $extension = $service->extensions->first(
            fn (ServiceExtension $candidate): bool => $candidate->code === $extensionCode
                && $candidate->treatment_mode->value === $variant->treatment_mode->value,
        );

        if (! $extension instanceof ServiceExtension) {
            throw ValidationException::withMessages([
                'extension' => __('validation.exists', ['attribute' => 'extension']),
            ]);
        }

        return $extension;
    }
}
