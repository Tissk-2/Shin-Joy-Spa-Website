<?php

namespace Tests\Fixtures\Outbox;

use App\Contracts\OutboxHandler;
use App\Exceptions\Outbox\OutboxPermanentException;
use App\Models\OutboxMessage;

final class PermanentOutboxHandler implements OutboxHandler
{
    public static string $reason = 'permanent failure';

    public function handle(OutboxMessage $message): void
    {
        throw new OutboxPermanentException(self::$reason);
    }
}
