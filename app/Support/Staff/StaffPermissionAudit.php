<?php

namespace App\Support\Staff;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StaffPermissionAudit
{
    public static function requireSchema(): void
    {
        if (! Schema::hasTable('staff_permission_changes') || ! Schema::hasColumns('staff_permission_changes', [
            'id', 'target_user_id', 'target_name', 'target_email', 'target_role', 'actor_user_id',
            'actor_name', 'actor_email', 'actor_role', 'was_allowed', 'is_allowed', 'channel', 'created_at',
        ])) {
            throw new StaffSchemaException('Staff permission history schema is incomplete. Run the staff permission history migration.');
        }
    }

    public static function record(User $target, ?User $actor, bool $before, bool $after, ?string $command = null): void
    {
        (new StaffAccessLock)->assertHeld();
        self::requireSchema();
        DB::table('staff_permission_changes')->insert([
            'target_user_id' => $target->id, 'target_name' => $target->name, 'target_email' => $target->email,
            'target_role' => $target->role->value, 'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name ?? 'CLI operator ('.$command.')',
            'actor_email' => $actor?->email, 'actor_role' => $actor?->role->value,
            'was_allowed' => $before, 'is_allowed' => $after, 'channel' => $actor === null ? 'cli' : 'dashboard',
            'created_at' => now('UTC'),
        ]);
    }

    public static function clearForRoleChange(User $target, string $command): void
    {
        (new StaffAccessLock)->assertHeld();
        if (! Schema::hasColumn('users', 'can_manage_bookings') || $target->can_manage_bookings !== true) {
            return;
        }
        // B1a command compatibility before the additive audit migration. A
        // present but broken audit schema must never become unaudited history.
        if (Schema::hasTable('staff_permission_changes')) {
            self::record($target, null, true, false, $command);
        }
        User::whereKey($target->id)->update(['can_manage_bookings' => false]);
    }
}
