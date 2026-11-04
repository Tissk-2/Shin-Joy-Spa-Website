<?php

namespace Tests\Fixtures\Outbox;

use App\Contracts\OutboxHandler;
use App\Models\OutboxMessage;

final class SecondaryFakeOutboxHandler implements OutboxHandler
{
    public function handle(OutboxMessage $message): void
    {
        //
    }
}
