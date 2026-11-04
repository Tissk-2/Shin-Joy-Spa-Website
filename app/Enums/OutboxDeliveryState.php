<?php

namespace App\Enums;

enum OutboxDeliveryState: string
{
    case Pending = 'pending';
    case Leased = 'leased';
    case Retry = 'retry';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
