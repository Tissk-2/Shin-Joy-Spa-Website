<?php

namespace App\Actions;

use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use App\Support\Rooms\RoomBlockActor;
use App\Support\Rooms\RoomBlockInput;
use App\Support\Rooms\RoomBlockInterval;
use App\Support\Rooms\RoomWriteSchema;
use App\Support\Scheduling\ScheduleLock;
use App\Support\Staff\StaffAccessLock;
use App\Support\Staff\StaffSchemaException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveRoomBlock
{
    public function __invoke(User $actor, ?int $blockId, array $validated): RoomBlock
    {
        return $this->guard($actor, function (User $current, CarbonImmutable $now) use ($blockId, $validated): RoomBlock {
            $input = (new RoomBlockInput)->validate($validated, $blockId !== null);
            $block = $blockId === null ? null : RoomBlock::query()->whereKey($blockId)->lockForUpdate()->firstOrFail();

            return $this->save($current, $block, $input, $now);
        });
    }

    public function forAllRooms(User $actor, array $validated): array
    {
        return $this->guard($actor, function (User $current, CarbonImmutable $now) use ($validated): array {
            $input = (new RoomBlockInput)->validate($validated, false, true);
            $rooms = Room::query()->orderBy('id')->lockForUpdate()->get();
            if ($rooms->isEmpty()) {
                $this->fail('room', 'empty');
            }
            $blocks = [];
            foreach ($rooms as $room) {
                $blocks[] = $this->save($current, null, [...$input, 'roomId' => $room->id], $now);
            }

            return $blocks;
        });
    }

    private function guard(User $actor, Closure $operation): mixed
    {
        (new RoomWriteSchema)->requireReady();
        try {
            return (new StaffAccessLock(requireSchema: true))->run(function () use ($actor, $operation) {
                $current = (new RoomBlockActor)->authorize($actor, true);
                if (DB::table('schedule_guards')->where('id', 1)->lockForUpdate()->first() === null) {
                    $this->fail('room', 'unavailable');
                }

                return (new ScheduleLock)->run(fn () => $operation($current, CarbonImmutable::now('UTC')));
            });
        } catch (StaffSchemaException) {
            $this->fail('room', 'unavailable');
        }
    }

    private function save(User $actor, ?RoomBlock $block, array $input, CarbonImmutable $now): RoomBlock
    {
        $input = $this->period($block, $input, $now);
        $room = Room::query()->whereKey($input['roomId'])->lockForUpdate()->first();
        if ($room === null) {
            $this->fail('roomId', 'missing');
        }
        $this->conflicts($room, $block?->id, $input);
        $saved = $block ?? new RoomBlock;
        $saved->fill(['room_id' => $room->id, ...($input['preserveStart'] ? [] : ['starts_at' => $input['start']]), 'ends_at' => $input['end'], 'reason' => $input['reason'],
            'version' => $block === null ? 1 : $block->version + 1, 'created_by' => $block === null ? $actor->id : $block->created_by]);
        $saved->save();

        return $saved;
    }

    private function period(?RoomBlock $block, array $input, CarbonImmutable $now): array
    {
        $start = $block === null ? null : CarbonImmutable::parse($block->getRawOriginal('starts_at'), 'UTC');
        $end = $block === null ? null : CarbonImmutable::parse($block->getRawOriginal('ends_at'), 'UTC');
        if ($block !== null) {
            if (isset((new RoomBlockInterval)->loadTerminations([$block->id], true)[$block->id])) {
                $this->fail('block', 'terminated');
            }
            if ($block->version !== $input['version'] || $block->version >= 4294967295) {
                $this->fail('version', 'stale');
            }
            if ($end <= $now) {
                $this->fail('block', 'ended');
            }
            if ($start <= $now) {
                if ($input['roomId'] !== $block->room_id) {
                    $this->fail('roomId', 'active');
                }
                if ($input['startsAt'] !== $start->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i')) {
                    $this->fail('startsAt', 'active');
                }
                $input = [...$input, 'start' => $start];
            }
        }
        if (($block === null || $start > $now) && $input['start'] < $now->startOfMinute()) {
            $this->fail('startsAt', 'past');
        }
        if ($input['end'] <= $input['start'] || $input['end'] <= $now) {
            $this->fail('endsAt', 'end');
        }

        return [...$input, 'preserveStart' => $block !== null && $start <= $now];
    }

    private function conflicts(Room $room, ?int $blockId, array $input): void
    {
        // Lock a coarse superset, then compare instants. SQLite can store both
        // second and fractional timestamp strings; lexical equality is insufficient.
        $start = $input['start']->startOfSecond()->format('Y-m-d H:i:s');
        $end = $input['end']->endOfSecond()->format('Y-m-d H:i:s.u');
        $overlaps = fn ($row) => CarbonImmutable::parse($row->starts_at, 'UTC') < $input['end']
            && CarbonImmutable::parse($row->ends_at, 'UTC') > $input['start'];
        $candidates = DB::table('room_blocks')->where('room_id', $room->id)->where('starts_at', '<=', $end)->where('ends_at', '>=', $start)
            ->when($blockId !== null, fn ($q) => $q->where('id', '<>', $blockId))->orderBy('id')->lockForUpdate()->get();
        $interval = new RoomBlockInterval;
        $terminations = $interval->loadTerminations($candidates->pluck('id')->all(), true);
        $other = $candidates->filter(fn ($row) => $interval->overlaps($row, $terminations[$row->id] ?? null, $input['start'], $input['end']));
        $holds = DB::table('room_reservations')->where('room_id', $room->id)->whereNull('released_at')
            ->where('starts_at', '<=', $end)->where('ends_at', '>=', $start)->orderBy('id')->lockForUpdate()->get()->filter($overlaps);
        if ($other->isNotEmpty() || $holds->isNotEmpty()) {
            $bookings = DB::table('bookings')->whereIn('id', $holds->pluck('booking_id'))->orderBy('id')->lockForUpdate()->get();
            $references = $bookings->pluck('reference')->take(10)->implode(', ');
            throw ValidationException::withMessages(['room' => __($references === '' ? 'admin.blocks.conflict_without_references' : 'admin.blocks.conflict', ['room' => $room->code, 'references' => $references, 'count' => $holds->count() + $other->count()])]);
        }
    }

    private function fail(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __('admin.blocks.'.$key)]);
    }
}
