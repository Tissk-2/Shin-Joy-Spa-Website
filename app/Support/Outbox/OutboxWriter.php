<?php

namespace App\Support\Outbox;

use App\Contracts\OutboxHandler;
use App\Enums\OutboxDeliveryState;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;
use stdClass;

final class OutboxWriter
{
    public const SCHEMA_VERSION = 1;

    private const MAX_PAYLOAD_DEPTH = 128;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $type, string $deduplicationKey, array $payload): OutboxMessage
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Outbox messages must be recorded inside an active database transaction.');
        }

        $this->validateIdentifier($type, 120, 'event type');
        $this->validateIdentifier($deduplicationKey, 191, 'deduplication key');

        $subscriptions = $this->subscriptions($type);
        $encodedPayload = $this->encodePayload($payload);
        $keyHash = hash('sha256', $deduplicationKey);
        $now = now();

        $inserted = DB::table('outbox_messages')->insertOrIgnore([
            'type' => $type,
            'deduplication_key' => $deduplicationKey,
            'deduplication_hash' => $keyHash,
            'payload' => $encodedPayload,
            'schema_version' => self::SCHEMA_VERSION,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $message = OutboxMessage::query()
            ->where('deduplication_hash', $keyHash)
            ->lockForUpdate()
            ->first();

        if (! $message instanceof OutboxMessage) {
            throw new LogicException("Outbox message [{$deduplicationKey}] could not be read after recording.");
        }

        // Compare originals as well as the digest, including fresh inserts: never
        // accept a hash collision or database-coerced identity/content as a replay.
        if ($message->deduplication_key !== $deduplicationKey || $message->deduplication_hash !== $keyHash
            || $message->type !== $type || $message->getRawOriginal('payload') !== $encodedPayload) {
            throw new LogicException("Outbox deduplication key [{$deduplicationKey}] already exists with different content.");
        }

        if ((int) $inserted === 0) {
            return $message;
        }

        $deliveries = [];

        foreach (array_keys($subscriptions) as $handlerKey) {
            $deliveries[] = [
                'message_id' => $message->id,
                'handler_key' => $handlerKey,
                'handler_key_hash' => hash('sha256', $handlerKey),
                'state' => OutboxDeliveryState::Pending->value,
                'attempts' => 0,
                'available_at' => $now,
                'lease_token' => null,
                'lease_expires_at' => null,
                'completed_at' => null,
                'skipped_at' => null,
                'failed_at' => null,
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($deliveries !== []) {
            DB::table('outbox_deliveries')->insert($deliveries);
        }

        return $message;
    }

    /**
     * @return array<string, class-string<OutboxHandler>>
     */
    private function subscriptions(string $type): array
    {
        $events = config('outbox.events');

        if (! is_array($events)) {
            throw new LogicException('Outbox event subscriptions must be an array keyed by event type.');
        }

        if (! array_key_exists($type, $events)) {
            throw new LogicException("Unknown outbox event type [{$type}].");
        }

        $subscriptions = $events[$type];

        if (! is_array($subscriptions)) {
            throw new LogicException("Outbox subscriptions for [{$type}] must map stable handler keys to handler classes.");
        }

        foreach ($subscriptions as $handlerKey => $handlerClass) {
            if (! is_string($handlerKey)) {
                throw new LogicException("Outbox subscriptions for [{$type}] must be keyed by non-empty stable handler keys.");
            }
            $this->validateIdentifier($handlerKey, 120, 'handler key');

            if (! is_string($handlerClass) || trim($handlerClass) === '') {
                throw new LogicException("Outbox handler [{$handlerKey}] for [{$type}] must be a non-empty class name.");
            }

            if (! class_exists($handlerClass)) {
                throw new LogicException("Outbox handler class [{$handlerClass}] for [{$handlerKey}] does not exist.");
            }

            if (! is_subclass_of($handlerClass, OutboxHandler::class)) {
                throw new LogicException("Outbox handler [{$handlerClass}] for [{$handlerKey}] must implement ".OutboxHandler::class.'.');
            }
        }

        return $subscriptions;
    }

    private function validateIdentifier(string $value, int $maximum, string $label): void
    {
        if (! mb_check_encoding($value, 'UTF-8') || trim($value) === ''
            || mb_strlen($value, 'UTF-8') > $maximum || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new LogicException("Outbox {$label} must be non-empty UTF-8 without control characters and at most {$maximum} characters.");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodePayload(array $payload): string
    {
        try {
            return json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new LogicException('Outbox payload cannot be encoded as canonical JSON: '.$exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>|stdClass
     */
    private function canonicalize(array $payload, int $depth = 1): array|stdClass
    {
        // Bound traversal itself, before JSON encoding, so cyclic arrays cannot
        // exhaust the worker. Caller objects are never passed to a serializer.
        if ($depth > self::MAX_PAYLOAD_DEPTH) {
            throw new LogicException('Outbox payload exceeds the maximum nesting depth of '.self::MAX_PAYLOAD_DEPTH.'.');
        }
        $canonical = [];

        foreach ($payload as $key => $value) {
            $canonical[$key] = $this->canonicalValue($value, $depth);
        }

        if (! array_is_list($payload)) {
            ksort($canonical, SORT_STRING);

            // Sorting numeric map keys can make a PHP array look like a list;
            // explicitly retain the original JSON container shape.
            return (object) $canonical;
        }

        return $canonical;
    }

    private function canonicalValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->canonicalize($value, $depth + 1);
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new LogicException('Outbox payload floats must be finite.');
            }

            return $value;
        }

        throw new LogicException(sprintf(
            'Outbox payload values must be null, booleans, integers, finite floats, strings, or arrays; %s given.',
            get_debug_type($value),
        ));
    }
}
