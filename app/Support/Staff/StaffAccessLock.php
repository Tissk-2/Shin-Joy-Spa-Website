<?php

namespace App\Support\Staff;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use WeakMap;

final class StaffAccessLock
{
    /** @var WeakMap<Connection, int>|null */
    private static ?WeakMap $owners = null;

    public function __construct(private readonly bool $requireSchema = false) {}

    /**
     * Lock order: staff guard, fresh actor, schedule guard, booking, reservation.
     * Callbacks contain synchronous local domain work only. Existing transactions
     * retain commit/retry ownership. Legacy mode is limited to pre-owner schemas.
     */
    public function run(Closure $operation): mixed
    {
        $connection = DB::connection();
        self::$owners ??= new WeakMap;
        $previous = self::$owners[$connection] ?? null;
        if ($previous !== null) {
            $this->assertHeld();
        }
        // Preflight before starting our transaction avoids a SQLite read snapshot
        // before its first write. No arbitrary query error is a legacy signal.
        $guard = Schema::hasTable('staff_access_guards');
        $capability = Schema::hasColumn('users', 'can_manage_bookings');
        $legacy = ! $guard && ! $capability && ! config('booking.scheduling_enabled') && ! $this->requireSchema;
        if ((! $guard || ! $capability) && ! $legacy) {
            throw new StaffSchemaException('Staff authority schema is incomplete. Run the owner and staff permissions migration.');
        }
        $outermost = $connection->transactionLevel() === 0;

        return $connection->transaction(function () use ($connection, $operation, $previous, $legacy) {
            try {
                if ($previous === null) {
                    if ($legacy) {
                        // Old SQLite has no singleton. Serialize all user writers
                        // before deciding authority, without a prior user read.
                        $connection->table('users')->update(['id' => DB::raw('id')]);
                        if ($connection->table('users')->where('role', 'owner')->lockForUpdate()->first() !== null) {
                            throw new StaffSchemaException('Owner accounts require the full staff authority schema.');
                        }
                    } elseif ($connection->table('staff_access_guards')->where('id', 1)->increment('revision') !== 1) {
                        throw new StaffSchemaException('The staff access guard singleton is missing. Repair the staff authority schema.');
                    }
                }
                self::$owners[$connection] = $connection->transactionLevel();
                $result = $operation();
                $this->assertHeld();

                return $result;
            } finally {
                if ($previous === null) {
                    unset(self::$owners[$connection]);
                } else {
                    self::$owners[$connection] = $previous;
                }
            }
        }, $outermost ? 3 : 1);
    }

    public function assertHeld(): void
    {
        $connection = DB::connection();
        $level = self::$owners[$connection] ?? null;
        if ($level === null || $level < 1 || $connection->transactionLevel() !== $level) {
            throw new LogicException('Active staff guard ownership is required; do not switch connections or manage transactions inside the callback.');
        }
    }
}
