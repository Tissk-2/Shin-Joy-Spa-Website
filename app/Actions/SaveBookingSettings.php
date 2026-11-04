<?php

namespace App\Actions;

use App\Models\BookingSettings;
use App\Models\User;
use App\Support\Rooms\RoomInput;
use App\Support\Scheduling\BookingSettingsGuard;
use App\Support\Scheduling\BookingSettingsInput;
use Illuminate\Validation\ValidationException;

final class SaveBookingSettings
{
    public function __invoke(User $owner, array $input): BookingSettings
    {
        return (new BookingSettingsGuard)->run($owner, function () use ($input): BookingSettings {
            $normalized = (new BookingSettingsInput)->validate($input);
            $current = BookingSettings::query()->whereKey(1)->lockForUpdate()->first();
            $version = $current?->getRawOriginal('version');
            if (($current && (! RoomInput::integer($version, 1, 4294967294) || (int) $version !== $normalized['version']))
                || (! $current && $normalized['version'] !== 0)) {
                throw ValidationException::withMessages(['version' => __('admin.booking_settings.stale')]);
            }
            // A fresh model prevents mutating a caller's domain model. A full
            // explicit save may repair business fields, retaining version checks.
            $settings = $current ?? new BookingSettings(['id' => 1]);
            $settings->fill([...$normalized, 'version' => $current ? (int) $version + 1 : 1]);
            $settings->save();

            return $settings;
        });
    }
}
