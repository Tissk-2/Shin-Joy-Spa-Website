<?php

namespace App\Console\Commands;

use App\Support\Outbox\OutboxDispatcher;
use Illuminate\Console\Command;

final class DispatchOutbox extends Command
{
    /** @var string */
    protected $signature = 'outbox:dispatch {--limit=100}';

    /** @var string */
    protected $description = 'Dispatch one bounded batch of due outbox deliveries';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);

        if ($limit === false) {
            $this->error('The --limit option must be an integer between 1 and 1000.');

            return self::FAILURE;
        }

        $count = app(OutboxDispatcher::class)->dispatchBatch($limit);

        $this->line("Dispatched {$count} outbox deliveries.");

        return self::SUCCESS;
    }
}
