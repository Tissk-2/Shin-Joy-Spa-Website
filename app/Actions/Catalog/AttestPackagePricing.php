<?php

namespace App\Actions\Catalog;

use App\Enums\PricingBasis;
use App\Models\Service;
use App\Models\User;
use App\Support\Rooms\RoomOwner;
use App\Support\Staff\StaffAccessLock;
use App\Support\Staff\StaffSchemaException;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class AttestPackagePricing
{
    public function __invoke(
        User $actor,
        Service $service,
        int $version,
        PricingBasis $basis,
        int $minGuests,
        int $maxGuests,
    ): Service {
        if ($minGuests < 1 || $maxGuests > 4 || $minGuests > $maxGuests) {
            throw ValidationException::withMessages([
                'minGuests' => __('validation.booking.guest_coverage'),
            ]);
        }

        try {
            return (new StaffAccessLock(requireSchema: true))->run(function () use (
                $actor,
                $service,
                $version,
                $basis,
                $minGuests,
                $maxGuests,
            ): Service {
                $owner = (new RoomOwner)->authorize($actor, lock: true);
                $locked = Service::query()->whereKey($service->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->lock_version !== $version) {
                    throw ValidationException::withMessages([
                        'version' => SaveCatalogPackage::staleValidationMessage(),
                    ]);
                }

                $locked->pricing_basis = $basis;
                $locked->min_guests = $minGuests;
                $locked->max_guests = $maxGuests;
                $locked->pricing_verified_at = CarbonImmutable::now('UTC');
                $locked->pricing_verified_by = $owner->getKey();
                $locked->lock_version++;
                $locked->save();

                return $locked;
            });
        } catch (StaffSchemaException) {
            throw ValidationException::withMessages([
                'pricing' => __('validation.booking.pricing_unavailable'),
            ]);
        }
    }
}
