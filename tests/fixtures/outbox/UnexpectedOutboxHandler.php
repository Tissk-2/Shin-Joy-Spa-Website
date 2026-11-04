<?php

namespace Tests\Fixtures\Outbox;

use App\Contracts\OutboxHandler;
use App\Models\OutboxMessage;
use RuntimeException;

final class UnexpectedOutboxHandler implements OutboxHandler
{
    public const SECRET_MESSAGE = 'secret-message-that-must-not-be-stored';

    public function handle(OutboxMessage $message): void
    {
        throw new RuntimeException(self::SECRET_MESSAGE);
    }
}
