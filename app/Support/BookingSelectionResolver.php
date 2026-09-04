<?php

namespace App\Support;

use App\Models\Service;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class BookingSelectionResolver
{
    /**
     * @param Collection<int, Service> $packages
     * @return array{service: string, mode: string, duration: int|null, extension: string}
     */
    public function resolve(Request $request, Collection $packages): array
    {
        $serviceSlug = $this->stringQuery($request, 'service');
        $service = $packages->first(fn (Service $package): bool => $package->is_active && $package->slug === $serviceSlug);

        $mode = $service === null ? null : $this->stringQuery($request, 'mode');
        $variants = $service?->variants->filter(fn (ServiceVariant $variant): bool => $variant->is_active && $variant->treatment_mode->value === $mode);
        $mode = $variants?->isNotEmpty() ? $mode : null;

        $duration = $this->durationQuery($request);
        $variant = $mode === null ? null : $variants->first(
            fn (ServiceVariant $candidate): bool => $candidate->duration_minutes === $duration,
        );

        $extensionCode = $variant === null ? null : $this->stringQuery($request, 'extension');
        $extension = $variant === null ? null : $service->extensions->first(
            fn (ServiceExtension $candidate): bool => $candidate->is_active
                && $candidate->treatment_mode->value === $mode
                && $candidate->code === $extensionCode,
        );

        return [
            'service' => $service?->slug ?? '',
            'mode' => $mode ?? '',
            'duration' => $variant?->duration_minutes,
            'extension' => $extension?->code ?? '',
        ];
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }

    private function durationQuery(Request $request): ?int
    {
        $value = $request->query('duration');

        return is_string($value) && preg_match('/^\d{1,3}$/', $value) === 1 ? (int) $value : null;
    }
}
