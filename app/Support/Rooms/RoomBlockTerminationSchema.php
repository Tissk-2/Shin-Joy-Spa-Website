<?php

namespace App\Support\Rooms;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class RoomBlockTerminationSchema
{
    public function mode(): string
    {
        if (! Schema::hasTable('room_block_terminations')) {
            return 'absent';
        }

        return Schema::hasColumns('room_block_terminations', ['id', 'room_block_id', 'kind', 'occurred_at', 'reason',
            'actor_user_id', 'actor_name', 'actor_role', 'room_id', 'original_starts_at', 'original_ends_at',
            'original_reason', 'previous_version', 'resulting_version']) ? 'complete' : 'partial';
    }

    public function requireReadable(): string
    {
        $mode = $this->mode();
        if ($mode === 'partial') {
            $this->unavailable();
        }

        return $mode;
    }

    public function requireComplete(): void
    {
        if ($this->mode() !== 'complete') {
            $this->unavailable();
        }
    }

    public function unavailable(): never
    {
        throw ValidationException::withMessages(['room' => __('admin.blocks.termination_unavailable')]);
    }
}
