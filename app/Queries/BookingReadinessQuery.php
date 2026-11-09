<?php

namespace App\Queries;

use App\Models\Booking;
use App\Models\BookingSettings;
use App\Models\Room;
use App\Support\Scheduling\BookingAvailabilitySchema;
use App\Support\Scheduling\BookingAvailabilityUnavailable;
use App\Support\Scheduling\BookingSettingsInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BookingReadinessQuery
{
    /** @return list<string> */
    public function issues(CarbonImmutable $now): array
    {
        try {
            (new BookingAvailabilitySchema)->requireReadable();
        } catch (BookingAvailabilityUnavailable) {
            return ['[schema] Room scheduling tables are missing or incomplete. Run the pending migrations first.'];
        }

        $audit = new RoomAssignmentAudit;
        $issues = [];

        $settings = BookingSettings::query()->find(1);
        if ($settings === null) {
            $issues[] = '[settings] Booking settings have not been saved. The owner saves them at /admin/settings/booking.';
        } else {
            try {
                (new BookingSettingsInput)->persisted($settings);
            } catch (ValidationException) {
                $issues[] = '[settings] Saved booking settings are invalid. The owner re-saves them at /admin/settings/booking.';
            }
        }

        $services = (new PublicCatalogQuery)->categories()->flatMap->services->values();
        if ($services->isEmpty()) {
            $issues[] = '[pricing] No package is published.';
        }
        foreach ($services as $service) {
            if (! $service->hasVerifiedPricing()) {
                $issues[] = "[pricing] Package {$service->slug} has no verified pricing.";
            }
        }

        if (Room::query()->where('is_active', true)->doesntExist()) {
            $issues[] = '[rooms] No room is active.';
        }
        $byVariant = DB::table('room_service_variant')->join('rooms', 'rooms.id', '=', 'room_service_variant.room_id')
            ->where('rooms.is_active', true)->get(['room_service_variant.service_variant_id', 'rooms.capacity'])
            ->groupBy('service_variant_id');
        foreach ($services as $service) {
            foreach ($service->variants as $variant) {
                $capacities = $byVariant->get($variant->id, collect())->pluck('capacity')->map(fn ($c) => (int) $c)->all();
                if ($capacities === []) {
                    $issues[] = "[rooms] Variant {$variant->code} of package {$service->slug} has no active compatible room.";
                } elseif ($service->hasVerifiedPricing() && max($capacities) < $service->max_guests) {
                    // Customers may pick any group up to the attested maximum; the whole group shares one room.
                    $issues[] = "[rooms] Variant {$variant->code} of package {$service->slug} has no active compatible room for {$service->max_guests} guests.";
                }
            }
        }

        $today = $now->setTimezone('Asia/Jakarta')->toDateString();

        $noStart = Booking::query()
            ->whereIn('status', ['pending', 'awaiting_payment', 'confirmed'])
            ->whereNull('starts_at')
            ->whereDate('appointment_date', '>=', $today)
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->orderBy('id')
            ->get();
        foreach ($noStart as $booking) {
            $issues[] = "[assignments] Booking {$booking->reference} on {$booking->appointment_date->format('Y-m-d')} {$booking->appointment_time} WIB has no valid start time.";
        }

        foreach ($audit->unassigned($now) as $booking) {
            $issues[] = "[assignments] Booking {$booking->reference} on {$booking->appointment_date->format('Y-m-d')} {$booking->appointment_time} WIB is ".str_replace('_', ' ', $booking->status->value).' but holds no room.';
        }

        array_push($issues, ...$audit->pendingIssues($now));
        array_push($issues, ...$audit->conflicts($now));

        return $issues;
    }
}
