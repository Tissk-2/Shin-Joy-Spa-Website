<?php

namespace App\Support\Scheduling;

use App\Models\User;
use App\Support\Rooms\RoomOwner;
use App\Support\Staff\StaffAccessLock;
use App\Support\Staff\StaffSchemaException;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BookingSettingsGuard
{
    public function run(User $owner, Closure $operation): mixed
    {
        // Schema metadata is checked only before taking guards; never cached.
        (new BookingSettingsSchema)->requireReady();
        try {
            return (new StaffAccessLock(requireSchema: true))->run(function () use ($owner, $operation) {
                (new RoomOwner)->authorize($owner, lock: true);
                if (DB::table('schedule_guards')->where('id', 1)->lockForUpdate()->first() === null) {
                    $this->unavailable();
                }

                return (new ScheduleLock)->run($operation);
            });
        } catch (StaffSchemaException) {
            $this->unavailable();
        }
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['settings' => __('admin.booking_settings.unavailable')]);
    }
}
