<?php

namespace Tests\Feature\Outbox;

use App\Models\OutboxMessage;
use App\Support\Outbox\OutboxWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonSerializable;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Outbox\FakeOutboxHandler;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/outbox/FakeOutboxHandler.php';

class OutboxWriterBoundaryTest extends TestCase
{
    use DatabaseMigrations;

    public function test_recursive_payload_is_rejected_without_exhausting_the_worker(): void
    {
        $process = new Process([
            PHP_BINARY, '-d', 'memory_limit=64M', '-d', 'display_errors=0', '-d', 'log_errors=0',
            base_path('tests/fixtures/outbox/recursive-payload-worker.php'),
        ], base_path(), null, null, 20);

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('rejected-before-write', trim($process->getOutput()));
    }

    public function test_object_payload_is_rejected_without_executing_serialization_hooks(): void
    {
        $object = new class implements JsonSerializable
        {
            public bool $called = false;

            public function jsonSerialize(): mixed
            {
                $this->called = true;

                return 'side effect';
            }
        };

        $this->reject(fn () => $this->record('object', ['object' => $object]));
        $this->assertFalse($object->called);
        $this->assertSame(0, DB::table('outbox_messages')->count());
    }

    public function test_finite_payload_depth_boundary_is_explicit_and_rejected_before_writing(): void
    {
        $payload = ['leaf'];
        for ($depth = 1; $depth < 128; $depth++) {
            $payload = [$payload];
        }

        $message = $this->record('maximum-depth', $payload);
        $this->assertSame(json_encode($payload, JSON_THROW_ON_ERROR), $message->getRawOriginal('payload'));
        $this->reject(fn () => $this->record('too-deep', [$payload]));
        $this->assertSame(1, DB::table('outbox_messages')->count());
    }

    public function test_numeric_map_keeps_object_shape_and_cannot_replay_as_a_list(): void
    {
        $payload = ['items' => [1 => 'b', 0 => 'a']];
        $original = $payload;
        $message = $this->record('numeric-map', $payload);

        $this->assertSame('{"items":{"0":"a","1":"b"}}', $message->getRawOriginal('payload'));
        $this->assertSame($original, $payload);
        $this->reject(fn () => $this->record('numeric-map', ['items' => ['a', 'b']]));
        $this->assertSame(1, DB::table('outbox_messages')->count());

        $list = $this->record('list', ['items' => ['a', 'b']]);
        $this->assertSame('{"items":["a","b"]}', $list->getRawOriginal('payload'));
        $this->reject(fn () => $this->record('list', ['items' => ['b', 'a']]));
    }

    public function test_distinct_keys_remain_distinct_under_case_insensitive_database_comparison(): void
    {
        $this->useCaseInsensitiveIdentifiers();
        $first = $this->record('Event:A', ['booking_id' => 42]);
        $second = $this->record('event:a', ['booking_id' => 42]);
        $padded = $this->record('event:a ', ['booking_id' => 42]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($second->id, $padded->id);
        $this->assertSame('event:a', $second->deduplication_key);
        $this->assertSame('event:a ', $padded->deduplication_key);
        $this->assertSame($first->id, $this->record('Event:A', ['booking_id' => 42])->id);
        $this->assertSame(3, DB::table('outbox_messages')->count());
    }

    public function test_case_variant_handler_aliases_keep_independent_deliveries(): void
    {
        $this->useCaseInsensitiveIdentifiers();
        config(['outbox.events' => ['booking.created' => [
            'Primary' => FakeOutboxHandler::class,
            'primary' => FakeOutboxHandler::class,
        ]]]);

        $message = $this->record('handler-case', ['booking_id' => 42]);

        $this->assertSame(['Primary', 'primary'], $message->deliveries()->orderBy('id')->pluck('handler_key')->all());
        $this->assertSame($message->id, $this->record('handler-case', ['booking_id' => 42])->id);
        $this->assertSame(2, DB::table('outbox_deliveries')->count());
    }

    public function test_invalid_or_oversized_identifiers_fail_before_any_write(): void
    {
        foreach ([str_repeat('x', 121), "bad\xB1", "bad\0type", '', '   '] as $type) {
            config(['outbox.events' => [$type => []]]);
            $this->reject(fn () => $this->record('key', [], $type));
            $this->assertSame(0, DB::table('outbox_messages')->count());
        }

        config(['outbox.events' => ['booking.created' => []]]);
        foreach ([str_repeat('k', 192), "bad\xB1", "bad\0key", '', '   '] as $key) {
            $this->reject(fn () => $this->record($key, []));
            $this->assertSame(0, DB::table('outbox_messages')->count());
        }

        foreach ([str_repeat('h', 121), "bad\xB1", "bad\0alias", '', '   '] as $alias) {
            config(['outbox.events' => ['booking.created' => [$alias => FakeOutboxHandler::class]]]);
            $this->reject(fn () => $this->record('key', []));
            $this->assertSame(0, DB::table('outbox_messages')->count());
            $this->assertSame(0, DB::table('outbox_deliveries')->count());
        }
    }

    public function test_identifier_character_boundaries_preserve_multibyte_values(): void
    {
        $type = str_repeat('é', 120);
        $key = str_repeat('界', 191);
        $alias = str_repeat('é', 120);
        config(['outbox.events' => [$type => [$alias => FakeOutboxHandler::class]]]);

        $message = $this->record($key, ['booking_id' => 42], $type);

        $this->assertSame($type, $message->type);
        $this->assertSame($key, $message->deduplication_key);
        $this->assertSame([$alias], $message->deliveries()->pluck('handler_key')->all());
    }

    public function test_digest_match_with_a_different_original_key_is_rejected(): void
    {
        $message = $this->record('original', ['booking_id' => 42]);
        DB::table('outbox_messages')->where('id', $message->id)->update([
            'deduplication_hash' => hash('sha256', 'different'),
        ]);
        $before = DB::table('outbox_messages')->first();

        $this->reject(fn () => $this->record('different', ['booking_id' => 42]));

        $this->assertEquals($before, DB::table('outbox_messages')->first());
        $this->assertSame(1, DB::table('outbox_messages')->count());
    }

    /** @param array<array-key, mixed> $payload */
    private function record(string $key, array $payload, string $type = 'booking.created'): OutboxMessage
    {
        return DB::transaction(fn () => app(OutboxWriter::class)->record($type, $key, $payload));
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
        } catch (LogicException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected invalid outbox input to be rejected.');
    }

    private function useCaseInsensitiveIdentifiers(): void
    {
        $definitions = DB::table('sqlite_master')
            ->whereIn('tbl_name', ['outbox_messages', 'outbox_deliveries'])
            ->whereNotNull('sql')->get();
        Schema::drop('outbox_deliveries');
        Schema::drop('outbox_messages');

        foreach (['table', 'index'] as $kind) {
            foreach ($definitions->where('type', $kind) as $definition) {
                $sql = preg_replace('/("(?:deduplication_key|handler_key)" varchar(?:\(\d+\))?)/i', '$1 COLLATE NOCASE', $definition->sql);
                DB::statement($sql);
            }
        }

        $this->assertStringContainsString('COLLATE NOCASE', DB::table('sqlite_master')->where('name', 'outbox_messages')->value('sql'));
    }
}
