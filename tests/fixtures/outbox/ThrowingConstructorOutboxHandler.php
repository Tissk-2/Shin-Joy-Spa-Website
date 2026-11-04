<?php

namespace Tests\Fixtures\Outbox;

use App\Contracts\OutboxHandler;
use App\Models\OutboxMessage;
use RuntimeException;

final class ThrowingConstructorOutboxHandler implements OutboxHandler
{
    public function __construct()
    {
        throw new RuntimeException('secret connection string');
    }

    public function handle(OutboxMessage $message): void {}
}
