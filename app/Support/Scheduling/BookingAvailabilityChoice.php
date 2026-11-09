<?php

namespace App\Support\Scheduling;

use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Queries\PublicCatalogQuery;
use App\Support\BookingSelectionLimits;
use App\Support\Rooms\RoomInput;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class BookingAvailabilityChoice
{
    /** @return array{variant: ServiceVariant, duration: int} */
    public function resolve(array $selection): array
    {
        try {
            $service = (new PublicCatalogQuery)->findService($selection['service']);
        } catch (ModelNotFoundException) {
            $this->invalid('service');
        }
        if ($service->getRawOriginal('slug') !== $selection['service']) {
            $this->invalid('service');
        }
        $variant = $service->variants->first(fn (ServiceVariant $choice) => $choice->getRawOriginal('treatment_mode') === $selection['mode']
            && RoomInput::integer($choice->getRawOriginal('duration_minutes'), 1, BookingSelectionLimits::BASE_DURATION_MINUTES)
            && $choice->duration_minutes === $selection['duration']);
        if (! $variant) {
            $this->invalid('duration');
        }
        $extension = null;
        if ($selection['extension'] !== null) {
            $extension = $service->extensions->first(fn (ServiceExtension $choice) => $choice->code === $selection['extension'] && $choice->getRawOriginal('treatment_mode') === $selection['mode']);
            if (! $extension || ! RoomInput::integer($extension->getRawOriginal('duration_minutes'), 1, BookingSelectionLimits::TOTAL_DURATION_MINUTES)) {
                $this->invalid('extension');
            }
        }
        // Bounded trusted operands prevent overflow before addition.
        $duration = $variant->duration_minutes + ($extension?->duration_minutes ?? 0);
        if ($duration > BookingSelectionLimits::TOTAL_DURATION_MINUTES) {
            $this->invalid('extension');
        }

        return ['variant' => $variant, 'duration' => $duration];
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => __('validation.availability.choice')]);
    }
}
