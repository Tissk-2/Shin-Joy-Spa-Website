<?php

namespace Tests\Fixtures\Outbox;

use App\Contracts\OutboxHandler;
use App\Models\OutboxMessage;

final class FakeOutboxHandler implements OutboxHandler
{
    /** @var list<int> */
    public static array $handled = [];

    public function handle(OutboxMessage $message): void
    {
        self::$handled[] = $message->id;
    }
}
