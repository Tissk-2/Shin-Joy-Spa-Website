<?php

namespace App\Actions;

use App\Models\RoomBlock;
use App\Models\RoomBlockTermination;
use App\Models\User;
use App\Support\Rooms\RoomBlockActor;
use App\Support\Rooms\RoomBlockInterval;
use App\Support\Rooms\RoomBlockTerminationSchema;
use App\Support\Rooms\RoomInput;
use App\Support\Rooms\RoomWriteSchema;
use App\Support\Scheduling\ScheduleLock;
use App\Support\Staff\StaffAccessLock;
use App\Support\Staff\StaffSchemaException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TerminateRoomBlock
{
    public function __invoke(User $actor, int $blockId, array $input): RoomBlock
    {
        (new RoomWriteSchema)->requireReady();
        (new RoomBlockTerminationSchema)->requireComplete();
        try {
            return (new StaffAccessLock(requireSchema: true))->run(function () use ($actor, $blockId, $input) {
                $current = (new RoomBlockActor)->authorize($actor, true);
                if (DB::table('schedule_guards')->where('id', 1)->lockForUpdate()->first() === null) {
                    $this->fail('room', 'termination_unavailable');
                }

                return (new ScheduleLock)->run(function () use ($current, $blockId, $input) {
                    $now = CarbonImmutable::now('UTC');
                    $data = $this->input($input);
                    $block = RoomBlock::query()->whereKey($blockId)->lockForUpdate()->firstOrFail();

                    return $this->terminate($current, $block, $data, $now);
                });
            });
        } catch (StaffSchemaException) {
            $this->fail('room', 'termination_unavailable');
        }
    }

    private function terminate(User $actor, RoomBlock $block, array $data, CarbonImmutable $now): RoomBlock
    {
        $interval = new RoomBlockInterval;
        $terminal = $interval->loadTerminations([$block->id], true);
        if (isset($terminal[$block->id])) {
            $this->fail('block', 'terminated');
        }
        if ($block->version !== $data['version'] || $block->version >= 4294967295) {
            $this->fail('version', 'stale');
        }
        $start = $interval->instant($block, 'starts_at');
        $end = $interval->instant($block, 'ends_at');
        if ($end <= $start || ($data['intent'] === 'cancel_future' ? $start <= $now : ($start > $now || $end <= $now))) {
            $this->fail('intent', 'lifecycle_changed');
        }
        $previous = $block->version;
        // Update only the version/bookkeeping, preserving original raw interval bytes.
        $block->forceFill(['version' => $previous + 1])->save();
        RoomBlockTermination::create(['room_block_id' => $block->id, 'kind' => $data['intent'], 'occurred_at' => $now,
            'reason' => $data['reason'], 'actor_user_id' => $actor->id, 'actor_name' => $actor->name, 'actor_role' => $actor->role->value,
            'room_id' => $block->room_id, 'original_starts_at' => $start, 'original_ends_at' => $end,
            'original_reason' => $block->reason, 'previous_version' => $previous, 'resulting_version' => $previous + 1]);

        return $block;
    }

    private function input(array $input): array
    {
        foreach (array_diff(array_keys($input), ['intent', 'version', 'reason']) as $key) {
            $this->fail($key, 'invalid');
        }
        if (! is_string($input['intent'] ?? null) || ! in_array($input['intent'], ['cancel_future', 'end_now'], true)) {
            $this->fail('intent', 'invalid');
        }
        if (! RoomInput::integer($input['version'] ?? null, 1, 4294967295)) {
            $this->fail('version', 'invalid');
        }
        if (! is_string($input['reason'] ?? null) || trim($input['reason']) === '' || mb_strlen(trim($input['reason'])) > 1000) {
            $this->fail('reason', 'invalid');
        }

        return ['intent' => $input['intent'], 'version' => (int) $input['version'], 'reason' => trim($input['reason'])];
    }

    private function fail(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __('admin.blocks.'.$key)]);
    }
}
