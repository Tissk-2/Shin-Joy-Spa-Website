<?php

namespace App\Actions;

use App\Models\Room;
use App\Models\User;
use App\Support\Rooms\RoomInput;
use App\Support\Rooms\RoomOwner;
use App\Support\Rooms\RoomWriteSchema;
use App\Support\Scheduling\ScheduleLock;
use App\Support\Staff\StaffAccessLock;
use App\Support\Staff\StaffSchemaException;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveRoom
{
    public function __invoke(User $owner, ?int $roomId, array $validated): Room
    {
        (new RoomWriteSchema)->requireReady();

        try {
            return (new StaffAccessLock(requireSchema: true))->run(function () use ($owner, $roomId, $validated): Room {
                (new RoomOwner)->authorize($owner, lock: true);
                if (DB::table('schedule_guards')->where('id', 1)->lockForUpdate()->first() === null) {
                    $this->fail('room', 'unavailable');
                }

                return (new ScheduleLock)->run(function () use ($roomId, $validated): Room {
                    $input = (new RoomInput)->validate($validated, $roomId !== null);
                    $now = CarbonImmutable::now('UTC');
                    $room = $roomId === null ? new Room : Room::query()->whereKey($roomId)->lockForUpdate()->firstOrFail();
                    if ($roomId !== null && ($room->version !== $input['version'] || $room->version >= 4294967295)) {
                        $this->fail('version', 'stale');
                    }
                    $this->catalog($input['compatibleVariantIds']);
                    if ($roomId !== null) {
                        $this->protect($room, $input, $now);
                    }
                    if (DB::table('rooms')->where('code', $input['code'])->when($roomId !== null, fn ($q) => $q->where('id', '<>', $roomId))->lockForUpdate()->first() !== null) {
                        $this->fail('code', 'duplicate');
                    }
                    $room->fill(['code' => $input['code'], 'name' => $input['name'], 'type' => $input['type'],
                        'capacity' => $input['capacity'], 'cleaning_minutes' => $input['cleaningMinutes'],
                        'is_active' => $input['isActive'], 'version' => $roomId === null ? 1 : $room->version + 1]);
                    try {
                        $room->save();
                    } catch (UniqueConstraintViolationException $error) {
                        // Only the rooms.code constraint is a user-correctable duplicate.
                        $detail = strtolower((string) ($error->errorInfo[2] ?? ''));
                        if (! str_contains($detail, 'rooms.code') && ! str_contains($detail, 'rooms_code_unique')) {
                            throw $error;
                        }
                        $this->fail('code', 'duplicate');
                    }
                    // Eloquent sync() reads the pivot without a locking read and may
                    // see an old snapshot in a caller-owned MySQL transaction.
                    DB::table('room_service_variant')->where('room_id', $room->id)->delete();
                    if ($input['compatibleVariantIds'] !== []) {
                        DB::table('room_service_variant')->insert(array_map(fn ($id) => [
                            'room_id' => $room->id, 'service_variant_id' => $id,
                        ], $input['compatibleVariantIds']));
                    }

                    return $room;
                });
            });
        } catch (StaffSchemaException) {
            $this->fail('room', 'unavailable');
        }
    }

    private function catalog(array $ids): void
    {
        $variants = DB::table('service_variants')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        $services = DB::table('services')->whereIn('id', $variants->pluck('service_id'))->orderBy('id')->lockForUpdate()->get();
        $categories = DB::table('service_categories')->whereIn('id', $services->pluck('category_id'))->orderBy('id')->lockForUpdate()->get();
        if ($variants->count() !== count($ids) || $variants->contains(fn ($v) => ! $services->contains('id', $v->service_id))
            || $services->contains(fn ($s) => ! $categories->contains('id', $s->category_id))) {
            $this->fail('compatibleVariantIds', 'catalog_missing');
        }
    }

    private function protect(Room $room, array $input, CarbonImmutable $now): void
    {
        $currentIds = DB::table('room_service_variant')->where('room_id', $room->id)->orderBy('service_variant_id')->lockForUpdate()->pluck('service_variant_id')->all();
        $removed = array_diff($currentIds, $input['compatibleVariantIds']) !== [];
        $lowerCapacity = $input['capacity'] < $room->capacity;
        $changedType = $input['type'] !== $room->type;
        $deactivating = $room->is_active && ! $input['isActive'];
        if (! $deactivating && ! $removed && ! $lowerCapacity && ! $changedType) {
            return;
        }
        $holds = DB::table('room_reservations')->where('room_id', $room->id)->whereNull('released_at')
            ->where('ends_at', '>', $now->format('Y-m-d H:i:s.u'))->orderBy('id')->lockForUpdate()->get();
        $bookings = DB::table('bookings')->whereIn('id', $holds->pluck('booking_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $conflicts = [];
        foreach ($holds as $hold) {
            $booking = $bookings->get($hold->booking_id);
            $invalid = $deactivating || ! $booking
                || ($lowerCapacity && (! RoomInput::integer($booking->guests, 1, 4294967295) || $booking->guests > $input['capacity']))
                || ($changedType && $booking->requested_room_type !== null && $booking->requested_room_type !== $input['type'])
                || ($removed && (! RoomInput::integer($booking->service_variant_id, 1, PHP_INT_MAX) || ! in_array((int) $booking->service_variant_id, $input['compatibleVariantIds'], true)));
            if ($invalid) {
                $conflicts[] = $booking?->reference ?: '#'.$hold->booking_id;
            }
        }
        if ($conflicts !== []) {
            throw ValidationException::withMessages(['room' => __('admin.rooms.conflict', [
                'references' => implode(', ', array_slice(array_unique($conflicts), 0, 10)), 'count' => count($conflicts),
            ])]);
        }
    }

    private function fail(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __('admin.rooms.'.$key)]);
    }
}
