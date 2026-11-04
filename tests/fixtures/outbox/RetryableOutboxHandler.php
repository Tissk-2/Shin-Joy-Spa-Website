<?php

namespace Tests\Fixtures\Outbox;

use App\Contracts\OutboxHandler;
use App\Exceptions\Outbox\OutboxRetryableException;
use App\Models\OutboxMessage;

final class RetryableOutboxHandler implements OutboxHandler
{
    public static string $reason = 'temporary failure';

    public function handle(OutboxMessage $message): void
    {
        throw new OutboxRetryableException(self::$reason);
    }
}
