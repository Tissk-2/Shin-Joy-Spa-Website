<?php

namespace App\Console\Commands;

use App\Enums\OutboxDeliveryState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RequeueOutboxDeliveries extends Command
{
    /** @var string */
    protected $signature = 'outbox:requeue {ids* : Failed delivery IDs to requeue} {--yes : Skip the confirmation prompt}';

    /** @var string */
    protected $description = 'Give failed outbox deliveries a fresh retry budget';

    public function handle(): int
    {
        $ids = array_values(array_unique($this->argument('ids')));

        foreach ($ids as $id) {
            // \z (not $) rejects a trailing newline; 19 digits keeps intval() from clamping.
            if (preg_match('/^[1-9][0-9]{0,18}\z/', $id) !== 1) {
                $this->error("Invalid delivery ID [{$id}].");

                return self::FAILURE;
            }
        }

        if (count($ids) > 100) {
            $this->error('Requeue at most 100 deliveries at a time.');

            return self::FAILURE;
        }

        $ids = array_map('intval', $ids);

        $rows = DB::table('outbox_deliveries')->whereIn('id', $ids)->get()->keyBy('id');

        $failed = [];
        $unavailable = 0;

        foreach ($ids as $id) {
            $row = $rows->get($id);

            if ($row === null) {
                $this->line("#{$id}: not found");
                $unavailable++;

                continue;
            }

            if ($row->state !== OutboxDeliveryState::Failed->value) {
                $this->line("#{$id}: {$row->state}, not failed");
                $unavailable++;

                continue;
            }

            $failed[$id] = $row;
            $this->line("#{$id}: {$row->handler_key}, attempts {$row->attempts}, last error: {$row->last_error}");
        }

        if ($failed === []) {
            $this->line('No failed deliveries to requeue.');

            return $unavailable > 0 ? self::FAILURE : self::SUCCESS;
        }

        $count = count($failed);

        if (! $this->option('yes') && ! $this->confirm("Requeue {$count} failed deliveries? They may send their notifications again.", false)) {
            $this->warn('No changes were made.');

            return self::SUCCESS;
        }

        $now = now();
        $requeued = 0;

        foreach ($failed as $id => $row) {
            // Compare-and-set: a row that left the failed state since the listing
            // belongs to another actor and must not be given a new budget.
            $updated = DB::table('outbox_deliveries')
                ->where('id', $id)
                ->where('state', OutboxDeliveryState::Failed->value)
                ->update([
                    'state' => OutboxDeliveryState::Retry->value,
                    'attempts' => 0,
                    'available_at' => $now,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'failed_at' => null,
                    'last_error' => null,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                $this->line("#{$id}: changed state, not requeued");

                continue;
            }

            Log::info('Outbox delivery requeued.', [
                'delivery_id' => $id,
                'handler_key' => $row->handler_key,
                'previous_error' => $row->last_error,
            ]);

            $requeued++;
        }

        $requested = count($ids);
        $this->line("Requeued {$requeued} of {$requested} deliveries.");

        return $requeued === $requested ? self::SUCCESS : self::FAILURE;
    }
}
