<?php

namespace App\Jobs;

use App\Contracts\OutboxHandler;
use App\Enums\OutboxDeliveryState;
use App\Exceptions\Outbox\OutboxPermanentException;
use App\Exceptions\Outbox\OutboxRetryableException;
use App\Exceptions\Outbox\OutboxSkippedException;
use App\Models\OutboxDelivery;
use App\Models\OutboxMessage;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliverOutboxMessage implements ShouldQueue
{
    public int $tries = 1;

    public function __construct(
        public readonly int $deliveryId,
        public readonly string $leaseToken,
    ) {}

    public function handle(): void
    {
        $attempts = $this->claimAttempt();

        if ($attempts === null) {
            return;
        }

        $delivery = OutboxDelivery::query()->with('message')->find($this->deliveryId);

        if (! $delivery instanceof OutboxDelivery || ! $delivery->message instanceof OutboxMessage) {
            return;
        }

        [$handlerClass, $failure] = $this->resolveHandler($delivery->message->type, $delivery->handler_key);

        if ($handlerClass === null) {
            $this->markFailed('permanent: '.$failure);

            return;
        }

        try {
            $handler = app($handlerClass);
        } catch (BindingResolutionException) {
            $this->markFailed('permanent: handler could not be resolved');

            return;
        } catch (Throwable $exception) {
            $this->handleFailure('unexpected: '.$exception::class, $attempts);

            return;
        }

        try {
            $handler->handle($delivery->message);
        } catch (OutboxRetryableException $exception) {
            $this->handleFailure('retryable: '.$exception->reason(), $attempts);

            return;
        } catch (OutboxSkippedException $exception) {   // mutation K
            $this->markSkipped($exception->reason());

            return;
        } catch (OutboxPermanentException $exception) {
            $this->markFailed('permanent: '.$exception->reason());

            return;
        } catch (Throwable $exception) {
            $this->handleFailure('unexpected: '.$exception::class, $attempts);

            return;
        }

        $this->markCompleted();
    }

    private function claimAttempt(): ?int
    {
        return DB::transaction(function (): ?int {
            $delivery = OutboxDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

            if (! $delivery instanceof OutboxDelivery
                || $delivery->state !== OutboxDeliveryState::Leased
                || $delivery->lease_token === null
                || ! hash_equals($delivery->lease_token, $this->leaseToken)
                || $delivery->lease_expires_at === null
                || ! $delivery->lease_expires_at->isFuture()) {
                return null;
            }

            $affected = DB::table('outbox_deliveries')
                ->where('id', $delivery->id)
                ->where('state', OutboxDeliveryState::Leased->value)
                ->where('lease_token', $this->leaseToken)
                ->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                return null;
            }

            return (int) DB::table('outbox_deliveries')->where('id', $delivery->id)->value('attempts');
        });
    }

    /**
     * @return array{0: class-string<OutboxHandler>|null, 1: string|null}
     */
    private function resolveHandler(string $type, string $handlerKey): array
    {
        $events = config('outbox.events');

        if (! is_array($events)) {
            return [null, 'outbox.events is not an array'];
        }

        $subscriptions = $events[$type] ?? null;

        if (! is_array($subscriptions)) {
            return [null, 'event type is not configured'];
        }

        $handlerClass = $subscriptions[$handlerKey] ?? null;

        if (! is_string($handlerClass) || trim($handlerClass) === '') {
            return [null, 'handler is not configured'];
        }

        if (! class_exists($handlerClass)) {
            return [null, 'handler class does not exist'];
        }

        if (! is_subclass_of($handlerClass, OutboxHandler::class)) {
            return [null, 'handler class is invalid'];
        }

        return [$handlerClass, null];
    }

    private function markCompleted(): void
    {
        $now = now();

        DB::table('outbox_deliveries')
            ->where('id', $this->deliveryId)
            ->where('state', OutboxDeliveryState::Leased->value)
            ->where('lease_token', $this->leaseToken)
            ->update([
                'state' => OutboxDeliveryState::Completed->value,
                'completed_at' => $now,
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_error' => null,
                'updated_at' => $now,
            ]);
    }

    private function handleFailure(string $reason, int $attempts): void
    {
        $maximum = (int) config('outbox.max_attempts');

        if ($attempts >= $maximum) {
            $this->markFailed($reason);

            return;
        }

        $backoff = config('outbox.backoff_seconds');
        $seconds = $backoff[min($attempts - 1, count($backoff) - 1)];
        $now = now();

        DB::table('outbox_deliveries')
            ->where('id', $this->deliveryId)
            ->where('state', OutboxDeliveryState::Leased->value)
            ->where('lease_token', $this->leaseToken)
            ->update([
                'state' => OutboxDeliveryState::Retry->value,
                'available_at' => $now->toImmutable()->addSeconds($seconds),
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_error' => $this->sanitize($reason),
                'updated_at' => $now,
            ]);
    }

    // A deliberate, final outcome: nothing to send. last_error is the delivery's only reason column.
    private function markSkipped(string $reason): void
    {
        $now = now();

        DB::table('outbox_deliveries')
            ->where('id', $this->deliveryId)
            ->where('state', OutboxDeliveryState::Leased->value)
            ->where('lease_token', $this->leaseToken)
            ->update([
                'state' => OutboxDeliveryState::Skipped->value,
                'skipped_at' => $now,
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_error' => $this->sanitize('skipped: '.$reason),
                'updated_at' => $now,
            ]);
    }

    private function markFailed(string $reason): void
    {
        $now = now();

        DB::table('outbox_deliveries')
            ->where('id', $this->deliveryId)
            ->where('state', OutboxDeliveryState::Leased->value)
            ->where('lease_token', $this->leaseToken)
            ->update([
                'state' => OutboxDeliveryState::Failed->value,
                'failed_at' => $now,
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_error' => $this->sanitize($reason),
                'updated_at' => $now,
            ]);
    }

    private function sanitize(string $reason): string
    {
        // Scrub first: the /u regex returns null on invalid UTF-8, which would erase the error.
        $clean = preg_replace('/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', '', mb_scrub($reason, 'UTF-8')) ?? '';

        return mb_substr($clean, 0, 500, 'UTF-8');
    }
}
