<?php

namespace App\Support;

use App\Models\Service;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Queries\PublicCatalogQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BookingQuoteResolver
{
    public function __construct(
        private readonly PublicCatalogQuery $catalog,
        private readonly BookingPriceCalculator $prices,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function resolve(User $user, array $data): ResolvedBookingQuote
    {
        return $this->quote($data, $user->email);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function resolveForContact(array $data, ?string $email): ResolvedBookingQuote
    {
        return $this->quote($data, $email);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function quote(array $data, ?string $email): ResolvedBookingQuote
    {
        try {
            $service = $this->catalog->findService($data['service']);
        } catch (ModelNotFoundException) {
            $service = null;
        }

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

        $appointment = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i',
            $data['appointment_date'].' '.$data['appointment_time'],
            'Asia/Jakarta',
        );
        if ($appointment->addMinutes($variant->duration_minutes + ($extension?->duration_minutes ?? 0))
            ->gt($appointment->setTime(21, 0))) {
            throw ValidationException::withMessages([
                'time' => __('validation.booking.closing_time'),
            ]);
        }

        $guests = (int) $data['guests'];
        $verified = $service->hasVerifiedPricing();

        if (! $verified && config('booking.scheduling_enabled') === true) {
            throw ValidationException::withMessages([
                'service' => __('validation.booking.pricing_unverified'),
            ]);
        }

        $pricingBasis = null;
        $minGuests = null;
        $maxGuests = null;

        if ($verified) {
            $pricingBasis = $service->pricing_basis->value;
            $minGuests = (int) $service->min_guests;
            $maxGuests = (int) $service->max_guests;

            if ($guests < $minGuests || $guests > $maxGuests) {
                throw ValidationException::withMessages([
                    'guests' => __('validation.booking.guest_coverage'),
                ]);
            }

            try {
                $totalPrice = $this->prices->total(
                    $service->pricing_basis,
                    (int) $variant->price_idr,
                    (int) ($extension?->price_idr ?? 0),
                    $guests,
                    $minGuests,
                    $maxGuests,
                );
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'service' => __('validation.booking.pricing_unavailable'),
                ]);
            }
        } else {
            $totalPrice = (int) $variant->price_idr + (int) ($extension?->price_idr ?? 0);
        }

        return new ResolvedBookingQuote([
            'service_id' => $service->id,
            'service_variant_id' => $variant->id,
            'service_extension_id' => $extension?->id,
            'customer_name' => CustomerIdentity::name((string) $data['name']),
            'customer_email' => $email === null ? null : CustomerIdentity::email($email),
            'customer_phone' => CustomerIdentity::phone((string) ($data['phone'] ?? '')),
            'appointment_date' => (string) $data['appointment_date'],
            'appointment_time' => (string) $data['appointment_time'],
            'appointment_timezone' => 'Asia/Jakarta',
            'guests' => $guests,
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
            'total_price_idr' => $totalPrice,
            'pricing_basis' => $pricingBasis,
            'min_guests' => $minGuests,
            'max_guests' => $maxGuests,
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
