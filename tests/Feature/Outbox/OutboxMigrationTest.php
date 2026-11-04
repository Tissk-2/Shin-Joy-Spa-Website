<?php

namespace Tests\Feature\Outbox;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OutboxMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_schema_contains_outbox_tables_columns_defaults_and_uniqueness(): void
    {
        $this->assertTrue(Schema::hasTable('outbox_messages'));
        $this->assertTrue(Schema::hasTable('outbox_deliveries'));

        $messages = collect(Schema::getColumns('outbox_messages'))->keyBy('name');
        foreach (['id', 'type', 'deduplication_key', 'deduplication_hash', 'payload', 'schema_version', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue($messages->has($column), "Missing outbox_messages.{$column}.");
        }
        foreach (['type', 'deduplication_key', 'deduplication_hash', 'payload', 'schema_version'] as $column) {
            $this->assertFalse($messages[$column]['nullable'], "outbox_messages.{$column} must not be nullable.");
        }

        $deliveries = collect(Schema::getColumns('outbox_deliveries'))->keyBy('name');
        $required = [
            'id', 'message_id', 'handler_key', 'handler_key_hash', 'state', 'attempts', 'available_at',
            'lease_token', 'lease_expires_at', 'completed_at', 'skipped_at', 'failed_at',
            'last_error', 'created_at', 'updated_at',
        ];
        foreach ($required as $column) {
            $this->assertTrue($deliveries->has($column), "Missing outbox_deliveries.{$column}.");
        }
        foreach (['message_id', 'handler_key', 'handler_key_hash', 'state', 'attempts', 'available_at'] as $column) {
            $this->assertFalse($deliveries[$column]['nullable'], "outbox_deliveries.{$column} must not be nullable.");
        }
        foreach (['lease_token', 'lease_expires_at', 'completed_at', 'skipped_at', 'failed_at', 'last_error'] as $column) {
            $this->assertTrue($deliveries[$column]['nullable'], "outbox_deliveries.{$column} must be nullable.");
        }

        DB::table('outbox_messages')->insert([
            'type' => 'booking.created',
            'deduplication_key' => 'migration-defaults',
            'deduplication_hash' => hash('sha256', 'migration-defaults'),
            'payload' => '{"booking_id":1}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $message = DB::table('outbox_messages')->where('deduplication_key', 'migration-defaults')->first();
        $this->assertSame(1, (int) $message->schema_version);

        DB::table('outbox_deliveries')->insert([
            'message_id' => $message->id,
            'handler_key' => 'primary',
            'handler_key_hash' => hash('sha256', 'primary'),
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $delivery = DB::table('outbox_deliveries')->where('message_id', $message->id)->first();
        $this->assertSame('pending', $delivery->state);
        $this->assertSame(0, (int) $delivery->attempts);
        $this->assertNull($delivery->lease_token);
        $this->assertNull($delivery->lease_expires_at);
        $this->assertNull($delivery->completed_at);
        $this->assertNull($delivery->skipped_at);
        $this->assertNull($delivery->failed_at);
        $this->assertNull($delivery->last_error);

        $duplicateMessage = DB::table('outbox_messages')->insertOrIgnore([
            'type' => 'booking.created',
            'deduplication_key' => 'migration-defaults',
            'deduplication_hash' => hash('sha256', 'migration-defaults'),
            'payload' => '{"booking_id":1}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $duplicateDelivery = DB::table('outbox_deliveries')->insertOrIgnore([
            'message_id' => $message->id,
            'handler_key' => 'primary',
            'handler_key_hash' => hash('sha256', 'primary'),
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(0, $duplicateMessage);
        $this->assertSame(0, $duplicateDelivery);
        $this->assertSame(1, DB::table('outbox_messages')->count());
        $this->assertSame(1, DB::table('outbox_deliveries')->count());

        DB::table('outbox_messages')->where('id', $message->id)->delete();
        $this->assertSame(0, DB::table('outbox_deliveries')->count());
    }

    public function test_additive_upgrade_preserves_existing_tables_and_rolls_back_only_outbox_tables(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.outbox_upgrade' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('outbox_upgrade');
        DB::setDefaultConnection('outbox_upgrade');

        try {
            Schema::create('bookings', function (Blueprint $table): void {
                $table->id();
                $table->string('reference')->unique();
                $table->unsignedTinyInteger('guests');
                $table->unsignedBigInteger('total_price_idr');
                $table->timestamps();
            });
            Schema::create('services', function (Blueprint $table): void {
                $table->id();
                $table->string('slug')->unique();
                $table->unsignedBigInteger('price_idr')->nullable();
                $table->timestamps();
            });

            DB::table('bookings')->insert([
                'reference' => 'SJ-OUTBOX-UPGRADE', 'guests' => 2, 'total_price_idr' => 340000,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('services')->insert([
                'slug' => 'existing-package', 'price_idr' => 265000,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $existingColumns = [
                'bookings' => collect(Schema::getColumns('bookings'))->pluck('name')->sort()->values()->all(),
                'services' => collect(Schema::getColumns('services'))->pluck('name')->sort()->values()->all(),
            ];

            $migration = require database_path('migrations/2026_10_22_000002_create_outbox.php');
            $migration->up();

            $this->assertTrue(Schema::hasTable('outbox_messages'));
            $this->assertTrue(Schema::hasTable('outbox_deliveries'));
            $this->assertSame(
                $existingColumns['bookings'],
                collect(Schema::getColumns('bookings'))->pluck('name')->sort()->values()->all(),
            );
            $this->assertSame(
                $existingColumns['services'],
                collect(Schema::getColumns('services'))->pluck('name')->sort()->values()->all(),
            );
            $this->assertSame(340000, (int) DB::table('bookings')->where('reference', 'SJ-OUTBOX-UPGRADE')->value('total_price_idr'));
            $this->assertSame(265000, (int) DB::table('services')->where('slug', 'existing-package')->value('price_idr'));

            $migration->down();

            $this->assertFalse(Schema::hasTable('outbox_messages'));
            $this->assertFalse(Schema::hasTable('outbox_deliveries'));
            $this->assertSame(
                $existingColumns['bookings'],
                collect(Schema::getColumns('bookings'))->pluck('name')->sort()->values()->all(),
            );
            $this->assertSame(
                $existingColumns['services'],
                collect(Schema::getColumns('services'))->pluck('name')->sort()->values()->all(),
            );
            $this->assertSame(340000, (int) DB::table('bookings')->where('reference', 'SJ-OUTBOX-UPGRADE')->value('total_price_idr'));
            $this->assertSame(265000, (int) DB::table('services')->where('slug', 'existing-package')->value('price_idr'));
            $this->assertSame(2, (int) DB::table('bookings')->where('reference', 'SJ-OUTBOX-UPGRADE')->value('guests'));
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('outbox_upgrade');
        }
    }
}
