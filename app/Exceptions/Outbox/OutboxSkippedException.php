<?php

namespace App\Exceptions\Outbox;

use RuntimeException;

final class OutboxSkippedException extends RuntimeException
{
    public function __construct(private readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
