<?php

namespace App\Support\Rooms;

use App\Support\Scheduling\ScheduleLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RoomBlockInterval
{
    public function loadTerminations(array $blockIds, bool $lock = false): array
    {
        if ($lock) {
            (new ScheduleLock)->assertHeld();
        }
        if ((new RoomBlockTerminationSchema)->requireReadable() === 'absent' || $blockIds === []) {
            return [];
        }
        $rows = DB::table('room_block_terminations')->whereIn('room_block_id', $blockIds)
            ->orderBy('room_block_id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        foreach ($rows as $row) {
            $this->validateTermination($row);
        }

        return $rows->keyBy('room_block_id')->all();
    }

    public function instant(object $block, string $field): CarbonImmutable
    {
        return $this->parse($block instanceof Model ? $block->getRawOriginal($field) : $block->$field);
    }

    public function effectiveEnd(object $block, ?object $termination): CarbonImmutable
    {
        $end = $this->instant($block, 'ends_at');
        if ($termination === null) {
            return $end;
        }
        $this->validateTermination($termination);

        return $termination->kind === 'cancel_future' ? $this->instant($block, 'starts_at')
            : $end->min($this->parse($termination->occurred_at));
    }

    public function overlaps(object $block, ?object $termination, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $existingStart = $this->instant($block, 'starts_at');
        $existingEnd = $this->effectiveEnd($block, $termination);

        return $existingEnd > $existingStart && $existingStart < $end && $existingEnd > $start;
    }

    private function validateTermination(object $row): void
    {
        $at = $this->parse($row->occurred_at);
        $start = $this->parse($row->original_starts_at);
        $end = $this->parse($row->original_ends_at);
        if ($end <= $start || ! in_array($row->kind, ['cancel_future', 'end_now'], true)
            || ($row->kind === 'cancel_future' ? $at >= $start : ($at < $start || $at >= $end))) {
            $this->invalid();
        }
    }

    private function parse(mixed $value): CarbonImmutable
    {
        try {
            if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/', $value)) {
                $this->invalid();
            }
            $date = CarbonImmutable::parse($value, 'UTC');
            if ($date->format('Y-m-d H:i:s') !== substr($value, 0, 19)) {
                $this->invalid();
            }

            return $date;
        } catch (Throwable $error) {
            if ($error instanceof ValidationException) {
                throw $error;
            }
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['room' => __('admin.blocks.invalid_terminal')]);
    }
}
