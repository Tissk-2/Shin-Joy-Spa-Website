<?php

namespace Tests\Feature\Outbox;

use App\Enums\OutboxDeliveryState;
use App\Models\OutboxDelivery;
use App\Models\OutboxMessage;
use App\Support\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use stdClass;
use Tests\Fixtures\Outbox\FakeOutboxHandler;
use Tests\Fixtures\Outbox\SecondaryFakeOutboxHandler;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/outbox/FakeOutboxHandler.php';
require_once __DIR__.'/../../fixtures/outbox/SecondaryFakeOutboxHandler.php';

// DatabaseMigrations deliberately avoids a wrapping test transaction so the
// outside-transaction and caller-rollback contracts can be asserted directly.
class OutboxWriterTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 10:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_record_rejects_calls_without_an_active_transaction(): void
    {
        $this->subscribe(['primary' => FakeOutboxHandler::class]);

        $this->assertSame(0, DB::transactionLevel());
        $this->assertLogicFailure(
            fn () => $this->writer()->record('booking.created', 'key-outside', ['booking_id' => 1]),
            'transaction',
        );
        $this->assertSame(0, DB::table('outbox_messages')->count());
        $this->assertSame(0, DB::table('outbox_deliveries')->count());
    }

    public function test_record_persists_message_and_deliveries_within_the_caller_transaction(): void
    {
        $this->subscribe([
            'primary' => FakeOutboxHandler::class,
            'secondary' => SecondaryFakeOutboxHandler::class,
        ]);

        $message = DB::transaction(function (): OutboxMessage {
            $message = $this->writer()->record('booking.created', 'key-atomic', [
                'booking_id' => 42,
                'note' => 'confirmed',
            ]);

            // The writer must not open a nested transaction or commit by itself.
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame(1, DB::table('outbox_messages')->count());
            $this->assertSame(2, DB::table('outbox_deliveries')->count());

            return $message;
        });

        $this->assertSame(1, DB::table('outbox_messages')->count());
        $this->assertSame(2, DB::table('outbox_deliveries')->count());
        $this->assertSame('booking.created', $message->type);
        $this->assertSame('key-atomic', $message->deduplication_key);
        $this->assertSame(['booking_id' => 42, 'note' => 'confirmed'], $message->payload);
        $this->assertSame('{"booking_id":42,"note":"confirmed"}', $message->getRawOriginal('payload'));
        $this->assertSame(1, $message->schema_version);
        $this->assertSame('2026-10-22 10:00:00', $message->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            ['primary', 'secondary'],
            $message->deliveries()->orderBy('handler_key')->pluck('handler_key')->all(),
        );

        foreach ($message->deliveries as $delivery) {
            $this->assertSame(OutboxDeliveryState::Pending, $delivery->state);
            $this->assertSame(0, $delivery->attempts);
            $this->assertSame('2026-10-22 10:00:00', $delivery->available_at->format('Y-m-d H:i:s'));
            $this->assertNull($delivery->lease_token);
            $this->assertNull($delivery->lease_expires_at);
            $this->assertNull($delivery->completed_at);
            $this->assertNull($delivery->skipped_at);
            $this->assertNull($delivery->failed_at);
            $this->assertNull($delivery->last_error);
        }
    }

    public function test_caller_rollback_removes_the_message_and_every_delivery(): void
    {
        $this->subscribe(['primary' => FakeOutboxHandler::class]);

        try {
            DB::transaction(function (): void {
                $this->writer()->record('booking.created', 'key-rollback', ['booking_id' => 7]);
                throw new RuntimeException('force rollback');
            });
            $this->fail('Expected the caller transaction to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('outbox_messages')->count());
        $this->assertSame(0, DB::table('outbox_deliveries')->count());
    }

    public function test_equivalent_replay_returns_the_original_message_without_new_deliveries(): void
    {
        $this->subscribe(['primary' => FakeOutboxHandler::class]);

        $original = $this->record('key-replay', [
            'booking_id' => 9,
            'meta' => ['zeta' => [1, 2], 'alpha' => true],
        ]);
        $replay = $this->record('key-replay', [
            'meta' => ['alpha' => true, 'zeta' => [1, 2]],
            'booking_id' => 9,
        ]);

        $this->assertSame($original->id, $replay->id);
        $this->assertSame(1, DB::table('outbox_messages')->count());
        $this->assertSame(1, DB::table('outbox_deliveries')->count());
        $this->assertSame(['primary'], DB::table('outbox_deliveries')->pluck('handler_key')->all());
        $this->assertSame($original->getRawOriginal('payload'), DB::table('outbox_messages')->value('payload'));
    }

    public function test_replay_after_subscription_changes_keeps_the_original_payload_and_deliveries(): void
    {
        $this->subscribe(['primary' => FakeOutboxHandler::class]);

        $original = $this->record('key-config-change', ['booking_id' => 11, 'items' => [['b' => 2, 'a' => 1]]]);

        $this->subscribe(['secondary' => SecondaryFakeOutboxHandler::class]);

        $replay = $this->record('key-config-change', ['items' => [['a' => 1, 'b' => 2]], 'booking_id' => 11]);

        $this->assertSame($original->id, $replay->id);
        $this->assertSame(
            '{"booking_id":11,"items":[{"a":1,"b":2}]}',
            DB::table('outbox_messages')->value('payload'),
        );
        $this->assertSame(['primary'], DB::table('outbox_deliveries')->pluck('handler_key')->all());
        $this->assertSame(1, DB::table('outbox_deliveries')->count());
    }

    public function test_conflicting_payload_for_the_same_key_is_rejected_without_changing_records(): void
    {
        $this->subscribe(['primary' => FakeOutboxHandler::class]);

        $original = $this->record('key-conflict', ['booking_id' => 12, 'note' => 'first']);

        $this->assertLogicFailure(
            fn () => $this->record('key-conflict', ['booking_id' => 12, 'note' => 'second']),
            'different content',
        );

        $this->assertSame(1, DB::table('outbox_messages')->count());
        $this->assertSame(1, DB::table('outbox_deliveries')->count());
        $this->assertSame($original->getRawOriginal('payload'), DB::table('outbox_messages')->value('payload'));
        $this->assertSame('booking.created', DB::table('outbox_messages')->value('type'));
    }

    public function test_conflicting_type_for_the_same_key_is_rejected_without_changing_records(): void
    {
        config(['outbox.events' => [
            'booking.created' => ['primary' => FakeOutboxHandler::class],
            'booking.updated' => ['primary' => FakeOutboxHandler::class],
        ]]);

        $this->record('key-type-conflict', ['booking_id' => 13], 'booking.created');

        $this->assertLogicFailure(
            fn () => $this->record('key-type-conflict', ['booking_id' => 13], 'booking.updated'),
            'different content',
        );

        $this->assertSame(1, DB::table('outbox_messages')->count());
        $this->assertSame('booking.created', DB::table('outbox_messages')->value('type'));
        $this->assertSame(1, DB::table('outbox_deliveries')->count());
    }

    public function test_canonical_payload_preserves_list_order_and_scalar_types(): void
    {
        $this->subscribe(['primary' => FakeOutboxHandler::class]);

        $message = $this->record('key-canonical', [
            'tags' => ['zeta', 'alpha'],
            'nested' => ['b' => 2, 'a' => 1],
            'integer' => 1,
            'float' => 1.0,
            'boolean' => true,
            'null' => null,
            'string' => '1',
        ]);

        $this->assertSame(
            '{"boolean":true,"float":1.0,"integer":1,"nested":{"a":1,"b":2},"null":null,"string":"1","tags":["zeta","alpha"]}',
            $message->getRawOriginal('payload'),
        );
    }

    public function test_canonicalization_does_not_mutate_the_caller_payload(): void
    {
        $this->subscribe(['primary' => FakeOutboxHandler::class]);

        $payload = ['zeta' => ['b' => 2, 'a' => 1], 'alpha' => [3, 1, 2]];
        $unchanged = $payload;

        $this->record('key-immutability', $payload);

        $this->assertSame($unchanged, $payload);
    }

    public function test_unsupported_payload_values_are_rejected_before_writing(): void
    {
        $this->subscribe(['primary' => FakeOutboxHandler::class]);

        $resource = fopen('php://memory', 'r');
        $deep = 'leaf';
        for ($depth = 0; $depth < 600; $depth++) {
            $deep = [$deep];
        }

        $cases = [
            'object' => [new stdClass],
            'resource' => [$resource],
            'nan' => [NAN],
            'infinity' => [INF],
            'invalid-utf8' => ["\xB1\x31"],
            'excessive-depth' => [$deep],
        ];

        try {
            foreach ($cases as $label => $payload) {
                try {
                    $this->record('bad-'.$label, $payload);
                    $this->fail("Expected [{$label}] to be rejected.");
                } catch (LogicException) {
                    // Expected: unsupported payloads surface as LogicException.
                }
            }
        } finally {
            fclose($resource);
        }

        $this->assertSame(0, DB::table('outbox_messages')->count());
        $this->assertSame(0, DB::table('outbox_deliveries')->count());
    }

    public function test_delivery_rows_are_unique_per_handler_and_start_pending(): void
    {
        $this->subscribe([
            'primary' => FakeOutboxHandler::class,
            'secondary' => SecondaryFakeOutboxHandler::class,
        ]);

        $message = $this->record('key-deliveries', ['booking_id' => 14]);

        $deliveries = $message->deliveries()->orderBy('handler_key')->get();
        $this->assertCount(2, $deliveries);
        $this->assertSame(['primary', 'secondary'], $deliveries->pluck('handler_key')->all());

        foreach ($deliveries as $delivery) {
            $this->assertInstanceOf(OutboxDelivery::class, $delivery);
            $this->assertSame(OutboxDeliveryState::Pending, $delivery->state);
            $this->assertSame(0, $delivery->attempts);
        }

        $this->assertSame(
            ['pending', 'leased', 'retry', 'completed', 'skipped', 'failed'],
            array_map(static fn (OutboxDeliveryState $state): string => $state->value, OutboxDeliveryState::cases()),
        );

        $duplicate = DB::table('outbox_deliveries')->insertOrIgnore([
            'message_id' => $message->id,
            'handler_key' => 'primary',
            'handler_key_hash' => hash('sha256', 'primary'),
            'state' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, $duplicate);
        $this->assertSame(2, DB::table('outbox_deliveries')->count());
    }

    public function test_unknown_event_type_is_rejected_before_writing(): void
    {
        config(['outbox.events' => ['booking.created' => []]]);

        $this->assertLogicFailure(
            fn () => $this->record('key-unknown-type', ['booking_id' => 15], 'booking.cancelled'),
            'Unknown outbox event type',
        );

        $this->assertSame(0, DB::table('outbox_messages')->count());
        $this->assertSame(0, DB::table('outbox_deliveries')->count());
    }

    public function test_configured_event_without_subscribers_records_a_message_without_deliveries(): void
    {
        // An explicitly configured empty subscriber map is valid and distinct
        // from an unknown event type.
        config(['outbox.events' => ['booking.created' => []]]);

        $message = $this->record('key-no-subscribers', ['booking_id' => 16]);

        $this->assertSame(1, DB::table('outbox_messages')->count());
        $this->assertSame(0, DB::table('outbox_deliveries')->count());
        $this->assertSame('booking.created', $message->type);
    }

    public function test_invalid_handler_subscriptions_are_rejected_before_writing(): void
    {
        $subscriptions = [
            'missing class' => ['primary' => 'App\\Handlers\\MissingOutboxHandler'],
            'non-handler class' => ['primary' => stdClass::class],
            'empty class' => ['primary' => ''],
            'non-class value' => ['primary' => 123],
            'list without stable keys' => [FakeOutboxHandler::class],
            'non-array map' => 'not-an-array',
        ];

        foreach ($subscriptions as $label => $handlers) {
            config(['outbox.events' => ['booking.created' => $handlers]]);

            try {
                $this->record('bad-handlers-'.str_replace(' ', '-', $label), ['booking_id' => 17]);
                $this->fail("Expected [{$label}] subscriptions to be rejected.");
            } catch (LogicException) {
                // Expected: configuration errors stay visible.
            }

            $this->assertSame(0, DB::table('outbox_messages')->count());
        }
    }

    /** @param array<string, mixed> $handlers */
    private function subscribe(array $handlers): void
    {
        config(['outbox.events' => ['booking.created' => $handlers]]);
    }

    /** @param array<string, mixed> $payload */
    private function record(string $key, array $payload, ?string $type = null): OutboxMessage
    {
        return DB::transaction(fn (): OutboxMessage => $this->writer()->record(
            $type ?? 'booking.created',
            $key,
            $payload,
        ));
    }

    private function writer(): OutboxWriter
    {
        return $this->app->make(OutboxWriter::class);
    }

    private function assertLogicFailure(callable $operation, string $messageFragment): void
    {
        try {
            $operation();
        } catch (LogicException $exception) {
            $this->assertStringContainsString($messageFragment, $exception->getMessage());

            return;
        }

        $this->fail("Expected LogicException containing [{$messageFragment}].");
    }
}
