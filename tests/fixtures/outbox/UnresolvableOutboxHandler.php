<?php

namespace Tests\Fixtures\Outbox;

use App\Contracts\OutboxHandler;

// Implements the contract but cannot be built by the container.
abstract class UnresolvableOutboxHandler implements OutboxHandler {}
