<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PricingMigrationTest extends TestCase
{
    use RefreshDatabase;

    // Production break caught: fresh schemas must keep pricing optional and historical totals untouched.
    public function test_fresh_schema_is_nullable_and_legacy_rows_keep_their_existing_total(): void
    {
        foreach ([
            ['services', ['pricing_basis', 'min_guests', 'max_guests', 'pricing_verified_at', 'pricing_verified_by']],
            ['bookings', ['pricing_basis', 'min_guests', 'max_guests']],
        ] as [$table, $columns]) {
            $definitions = collect(Schema::getColumns($table))->keyBy('name');
            foreach ($columns as $column) {
                $this->assertTrue($definitions->has($column), "Missing {$table}.{$column}.");
                $this->assertTrue($definitions[$column]['nullable'], "{$table}.{$column} must be nullable.");
            }
        }

        $category = ServiceCategory::query()->create([
            'slug' => 'legacy', 'name_id' => 'Legacy', 'name_en' => 'Legacy', 'image_path' => '',
            'display_order' => 0, 'is_active' => true, 'published_at' => now(),
        ]);
        $service = Service::query()->create([
            'category_id' => $category->id, 'slug' => 'legacy-package',
            'name_id' => 'Legacy', 'name_en' => 'Legacy', 'description_id' => '', 'description_en' => '',
            'long_description_id' => '', 'long_description_en' => '', 'duration_minutes' => 90,
            'price_idr' => null, 'image_path' => '', 'gallery_paths' => [], 'popularity' => 0,
            'display_order' => 0, 'is_active' => true, 'published_at' => now(),
        ]);
        $booking = Booking::query()->forceCreate([
            'user_id' => null, 'reference' => 'SJ-LEGACY', 'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10',
            'request_fingerprint' => str_repeat('a', 64), 'status' => 'pending',
            'customer_name' => 'Legacy Guest', 'customer_email' => 'legacy@example.test', 'customer_phone' => '',
            'appointment_date' => '2026-09-07', 'appointment_time' => '10:30', 'appointment_timezone' => 'Asia/Jakarta',
            'guests' => 2, 'notes' => null, 'package_slug' => 'legacy-package',
            'package_name_id' => 'Legacy', 'package_name_en' => 'Legacy', 'package_is_vip' => false,
            'treatment_mode' => 'regular', 'base_choice_code' => 'legacy-regular-90',
            'base_duration_minutes' => 90, 'base_price_idr' => 265000, 'extension_choice_code' => null,
            'extension_duration_minutes' => null, 'extension_price_idr' => null,
            'total_duration_minutes' => 90, 'total_price_idr' => 340000,
        ]);

        $this->assertNull($service->fresh()->pricing_basis);
        $this->assertNull($service->fresh()->pricing_verified_by);
        $this->assertNull($booking->fresh()->pricing_basis);
        $this->assertSame(340000, $booking->fresh()->total_price_idr);
        $this->assertSame(2, $booking->fresh()->guests);
    }

    // Production break caught: an additive upgrade over pre-existing tables must preserve rows and add only nullable state.
    public function test_additive_upgrade_preserves_existing_service_and_booking_rows(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.pricing_upgrade' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('pricing_upgrade');
        DB::setDefaultConnection('pricing_upgrade');

        try {
            Schema::create('services', function (Blueprint $table): void {
                $table->id();
                $table->string('slug')->unique();
                $table->unsignedBigInteger('price_idr')->nullable();
                $table->timestamps();
            });
            Schema::create('bookings', function (Blueprint $table): void {
                $table->id();
                $table->string('reference')->unique();
                $table->unsignedTinyInteger('guests');
                $table->unsignedBigInteger('total_price_idr');
                $table->timestamps();
            });

            DB::table('services')->insert([
                'slug' => 'existing-package', 'price_idr' => 265000,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('bookings')->insert([
                'reference' => 'SJ-EXISTING', 'guests' => 2, 'total_price_idr' => 340000,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $migration = require database_path('migrations/2026_10_22_010001_add_booking_pricing_basis.php');
            $migration->up();

            foreach (['pricing_basis', 'min_guests', 'max_guests', 'pricing_verified_at', 'pricing_verified_by'] as $column) {
                $this->assertTrue(Schema::hasColumn('services', $column));
            }
            foreach (['pricing_basis', 'min_guests', 'max_guests'] as $column) {
                $this->assertTrue(Schema::hasColumn('bookings', $column));
            }

            $service = DB::table('services')->where('slug', 'existing-package')->first();
            $booking = DB::table('bookings')->where('reference', 'SJ-EXISTING')->first();
            $this->assertSame(265000, (int) $service->price_idr);
            $this->assertNull($service->pricing_basis);
            $this->assertNull($service->pricing_verified_at);
            $this->assertSame(340000, (int) $booking->total_price_idr);
            $this->assertSame(2, (int) $booking->guests);
            $this->assertNull($booking->pricing_basis);

            $migration->down();

            foreach (['pricing_basis', 'min_guests', 'max_guests', 'pricing_verified_at', 'pricing_verified_by'] as $column) {
                $this->assertFalse(Schema::hasColumn('services', $column));
            }
            $this->assertSame(265000, (int) DB::table('services')->where('slug', 'existing-package')->value('price_idr'));
            $this->assertSame(340000, (int) DB::table('bookings')->where('reference', 'SJ-EXISTING')->value('total_price_idr'));
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('pricing_upgrade');
        }
    }
}
