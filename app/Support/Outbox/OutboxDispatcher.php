<?php

namespace App\Support\Outbox;

use App\Enums\OutboxDeliveryState;
use App\Jobs\DeliverOutboxMessage;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class OutboxDispatcher
{
    public function __construct(private readonly QueueFactory $queue) {}

    public function dispatchBatch(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Outbox dispatch limit must be between 1 and 1000.');
        }

        // Inside a caller's transaction each claim is only a savepoint, so a push
        // would hand workers uncommitted leases (or run the handler inline on sync).
        if (DB::transactionLevel() > 0) {
            throw new LogicException('Outbox dispatch must run outside a database transaction.');
        }

        $now = now();
        $maximumAttempts = (int) config('outbox.max_attempts');
        $leaseSeconds = (int) config('outbox.lease_seconds');
        $candidates = $this->candidates($limit, $now);
        $claimed = [];

        foreach ($candidates as $candidate) {
            if ($candidate->state === OutboxDeliveryState::Leased->value
                && (int) $candidate->attempts >= $maximumAttempts) {
                $this->failExhausted((int) $candidate->id, $now, $maximumAttempts);

                continue;
            }

            $token = bin2hex(random_bytes(32));
            $affected = $this->claim((int) $candidate->id, $candidate->state, $token, $now, $leaseSeconds, $maximumAttempts);

            if ($affected === 1) {
                $claimed[] = ['id' => (int) $candidate->id, 'token' => $token];
            }
        }

        return $this->push($claimed);
    }

    /**
     * @return Collection<int, object>
     */
    private function candidates(int $limit, CarbonInterface $now)
    {
        return DB::table('outbox_deliveries')
            ->where(function ($query) use ($now): void {
                $query->where(function ($query) use ($now): void {
                    $query->whereIn('state', [OutboxDeliveryState::Pending->value, OutboxDeliveryState::Retry->value])
                        ->where('available_at', '<=', $now);
                })->orWhere(function ($query) use ($now): void {
                    $query->where('state', OutboxDeliveryState::Leased->value)
                        ->where('lease_expires_at', '<=', $now);
                });
            })
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'state', 'attempts']);
    }

    private function claim(int $deliveryId, string $observedState, string $token, CarbonInterface $now, int $leaseSeconds, int $maximumAttempts): int
    {
        return DB::transaction(fn (): int => DB::table('outbox_deliveries')
            ->where('id', $deliveryId)
            ->where('state', $observedState)
            ->where(function ($query) use ($now, $maximumAttempts): void {
                $query->where(function ($query) use ($now): void {
                    $query->whereIn('state', [OutboxDeliveryState::Pending->value, OutboxDeliveryState::Retry->value])
                        ->where('available_at', '<=', $now);
                })->orWhere(function ($query) use ($now, $maximumAttempts): void {
                    $query->where('state', OutboxDeliveryState::Leased->value)
                        ->where('lease_expires_at', '<=', $now)
                        ->where('attempts', '<', $maximumAttempts);
                });
            })
            ->update([
                'state' => OutboxDeliveryState::Leased->value,
                'lease_token' => $token,
                'lease_expires_at' => $now->toImmutable()->addSeconds($leaseSeconds),
                'updated_at' => $now,
            ]));
    }

    private function failExhausted(int $deliveryId, CarbonInterface $now, int $maximumAttempts): void
    {
        DB::table('outbox_deliveries')
            ->where('id', $deliveryId)
            ->where('state', OutboxDeliveryState::Leased->value)
            ->where('lease_expires_at', '<=', $now)
            ->where('attempts', '>=', $maximumAttempts)
            ->update([
                'state' => OutboxDeliveryState::Failed->value,
                'failed_at' => $now,
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_error' => 'retryable: lease expired after max attempts',
                'updated_at' => $now,
            ]);
    }

    /**
     * @param  list<array{id: int, token: string}>  $claimed
     */
    private function push(array $claimed): int
    {
        $accepted = 0;

        foreach ($claimed as $claim) {
            try {
                $this->queue->connection()->push(new DeliverOutboxMessage($claim['id'], $claim['token']));
                $accepted++;
            } catch (Throwable) {
                DB::table('outbox_deliveries')
                    ->where('id', $claim['id'])
                    ->where('state', OutboxDeliveryState::Leased->value)
                    ->where('lease_token', $claim['token'])
                    ->update([
                        'state' => OutboxDeliveryState::Retry->value,
                        'available_at' => now(),
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'updated_at' => now(),
                    ]);
            }
        }

        return $accepted;
    }
}
