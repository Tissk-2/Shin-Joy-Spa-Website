<?php

namespace Tests\Fixtures\Outbox;

use App\Contracts\OutboxHandler;
use App\Models\OutboxMessage;
use Closure;

final class CallbackOutboxHandler implements OutboxHandler
{
    /** @var (Closure(OutboxMessage): void)|null */
    public static ?Closure $callback = null;

    public function handle(OutboxMessage $message): void
    {
        if (self::$callback instanceof Closure) {
            (self::$callback)($message);
        }
    }
}
