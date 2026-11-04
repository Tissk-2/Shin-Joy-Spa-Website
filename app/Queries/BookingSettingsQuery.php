<?php

namespace App\Queries;

use App\Models\BookingSettings;
use App\Models\User;
use App\Support\Rooms\RoomInput;
use App\Support\Rooms\RoomOwner;
use App\Support\Scheduling\BookingSettingsGuard;
use App\Support\Scheduling\BookingSettingsInput;
use Illuminate\Validation\ValidationException;

final class BookingSettingsQuery
{
    public function get(User $owner): array
    {
        (new RoomOwner)->authorize($owner);
        try {
            return (new BookingSettingsGuard)->run($owner, function (): array {
                $settings = BookingSettings::query()->whereKey(1)->lockForUpdate()->first();
                if (! $settings) {
                    return ['state' => 'unconfigured', 'settings' => null, 'version' => 0];
                }
                try {
                    $input = (new BookingSettingsInput)->persisted($settings);
                } catch (ValidationException) {
                    $version = $settings->getRawOriginal('version');

                    return ['state' => 'invalid', 'settings' => null,
                        'version' => RoomInput::integer($version, 1, 4294967294) ? (int) $version : null];
                }
                $intervals = array_map(fn ($row) => ['weekday' => $row['weekday'], 'start' => BookingSettingsInput::time($row['start_minute']),
                    'end' => BookingSettingsInput::time($row['end_minute'])], $input['review_intervals']);

                return ['state' => 'configured', 'version' => $input['version'],
                    'settings' => [...$input, 'review_intervals' => $intervals]];
            });
        } catch (ValidationException $error) {
            if (! isset($error->errors()['settings'])) {
                throw $error;
            }

            return ['state' => 'unavailable', 'settings' => null, 'version' => null];
        }
    }
}
