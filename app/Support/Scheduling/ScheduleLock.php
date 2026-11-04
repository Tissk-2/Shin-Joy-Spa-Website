<?php

namespace App\Support\Scheduling;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use WeakMap;

final class ScheduleLock
{
    /** @var WeakMap<Connection, int>|null */
    private static ?WeakMap $owners = null;

    /**
     * Existing outer transactions own retry and commit. All schedule writers must
     * acquire this guard before booking/catalog/room locks, in that order.
     * Only synchronous local domain work belongs inside this boundary.
     */
    public function run(Closure $operation): mixed
    {
        $connection = DB::connection();
        self::$owners ??= new WeakMap;
        $nested = isset(self::$owners[$connection]);
        if ($nested) {
            $this->assertHeld();
        }
        $previous = self::$owners[$connection] ?? null;
        $outermost = $connection->transactionLevel() === 0;

        return $connection->transaction(function () use ($connection, $operation, $nested, $previous) {
            try {
                if (! $nested && $connection->table('schedule_guards')->where('id', 1)->increment('revision') !== 1) {
                    throw new LogicException('The schedule guard is missing.');
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
        if ($level === null || $connection->transactionLevel() !== $level || $level < 1) {
            throw new LogicException('Active schedule guard ownership is required; do not switch connections or manage transactions inside the callback.');
        }
    }
}
