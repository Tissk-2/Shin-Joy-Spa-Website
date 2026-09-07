<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\TreatmentMode;
use App\Models\Booking;
use App\Models\BookingStatusChange;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingSchemaTest extends TestCase
{
    use RefreshDatabase;

    // Production break caught: missing booking persistence columns would drop authoritative customer and catalog snapshots.
    public function test_bookings_table_contains_authoritative_snapshot_columns(): void
    {
        foreach ($this->expectedColumns() as $column) {
            $this->assertTrue(Schema::hasColumn('bookings', $column), "Missing bookings.{$column}");
        }
    }

    // Production break caught: changing backed values would make existing booking rows unreadable or expose unsupported statuses.
    public function test_booking_status_enum_contains_only_phase_one_statuses(): void
    {
        $this->assertSame(
            ['pending', 'confirmed', 'completed', 'cancelled'],
            array_map(fn (BookingStatus $status) => $status->value, BookingStatus::cases()),
        );
    }

    // Production break caught: an incomplete audit schema would lose actor snapshots, transition context, or request metadata.
    public function test_booking_status_changes_table_contains_immutable_audit_columns(): void
    {
        foreach ($this->expectedStatusChangeColumns() as $column) {
            $this->assertTrue(
                Schema::hasColumn('booking_status_changes', $column),
                "Missing booking_status_changes.{$column}",
            );
        }

        $this->assertFalse(Schema::hasColumn('booking_status_changes', 'updated_at'));
    }

    // Production break caught: database enum definitions must use backed scalar values for both sides of a transition.
    public function test_booking_status_change_migration_passes_scalar_status_values_to_the_schema_blueprint(): void
    {
        $allowedStatuses = [];

        Schema::shouldReceive('create')
            ->once()
            ->with('booking_status_changes', \Mockery::on(function (\Closure $callback) use (&$allowedStatuses): bool {
                $blueprint = new Blueprint(DB::connection(), 'booking_status_changes', $callback);

                foreach (['from_status', 'to_status'] as $columnName) {
                    $allowedStatuses[$columnName] = collect($blueprint->getColumns())
                        ->firstWhere('name', $columnName)
                        ?->allowed;
                }

                return true;
            }));

        $migration = require database_path('migrations/2026_09_07_000001_create_booking_status_changes_table.php');
        $migration->up();

        $this->assertSame([
            'from_status' => ['pending', 'confirmed', 'completed', 'cancelled'],
            'to_status' => ['pending', 'confirmed', 'completed', 'cancelled'],
        ], $allowedStatuses);
    }

    // Production break caught: removing enum or immutable timestamp casts would expose raw persistence values to audit consumers.
    public function test_booking_status_change_model_casts_statuses_and_created_at(): void
    {
        $change = BookingStatusChange::factory()->create([
            'from_status' => BookingStatus::Pending,
            'to_status' => BookingStatus::Confirmed,
        ])->fresh();

        $this->assertSame(BookingStatus::Pending, $change->from_status);
        $this->assertSame(BookingStatus::Confirmed, $change->to_status);
        $this->assertInstanceOf(CarbonImmutable::class, $change->created_at);
    }

    // Production break caught: MySQL and PostgreSQL grammars must receive scalar enum values, not PHP enum objects.
    public function test_booking_migration_passes_scalar_status_values_to_the_schema_blueprint(): void
    {
        $allowedStatuses = null;

        Schema::shouldReceive('create')
            ->once()
            ->with('bookings', \Mockery::on(function (\Closure $callback) use (&$allowedStatuses): bool {
                $blueprint = new Blueprint(DB::connection(), 'bookings', $callback);
                $statusColumn = collect($blueprint->getColumns())
                    ->firstWhere('name', 'status');

                $allowedStatuses = $statusColumn?->allowed;

                return true;
            }));

        $migration = require database_path('migrations/2026_09_05_000000_create_bookings_table.php');
        $migration->up();

        $this->assertSame(
            ['pending', 'confirmed', 'completed', 'cancelled'],
            $allowedStatuses,
        );
    }

    // Production break caught: query-builder writes must not persist statuses the booking domain cannot hydrate.
    public function test_bookings_table_rejects_unsupported_persisted_statuses(): void
    {
        $exception = null;

        try {
            DB::table('bookings')->insert($this->validBookingRow(['status' => 'refunded']));
        } catch (QueryException $queryException) {
            $exception = $queryException;
        }

        $this->assertInstanceOf(QueryException::class, $exception);
        $this->assertDatabaseCount('bookings', 0);
    }

    // Production break caught: removing casts would leak raw strings/numbers instead of typed booking snapshots.
    public function test_booking_model_casts_status_dates_booleans_and_amounts(): void
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'appointment_date' => '2026-09-15',
            'package_is_vip' => true,
            'guests' => 2,
            'base_duration_minutes' => 90,
            'base_price_idr' => 265000,
            'extension_duration_minutes' => 60,
            'extension_price_idr' => 75000,
            'total_duration_minutes' => 150,
            'total_price_idr' => 340000,
        ])->fresh();

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame('2026-09-15', $booking->appointment_date->format('Y-m-d'));
        $this->assertTrue($booking->package_is_vip);
        $this->assertSame(2, $booking->guests);
        $this->assertSame(90, $booking->base_duration_minutes);
        $this->assertSame(265000, $booking->base_price_idr);
        $this->assertSame(60, $booking->extension_duration_minutes);
        $this->assertSame(75000, $booking->extension_price_idr);
        $this->assertSame(150, $booking->total_duration_minutes);
        $this->assertSame(340000, $booking->total_price_idr);
    }

    // Production break caught: deleting customer or catalog rows must preserve operational booking history with immutable snapshots.
    public function test_booking_relationships_null_on_delete_without_rewriting_snapshots(): void
    {
        [$user, $service, $variant, $extension] = $this->createCatalogBookingOwners();

        $booking = Booking::factory()->for($user)->create([
            'service_id' => $service->id,
            'service_variant_id' => $variant->id,
            'service_extension_id' => $extension->id,
            'package_slug' => 'nezuko',
            'package_name_id' => 'Nezuko Lama',
            'package_name_en' => 'Old Nezuko',
            'package_is_vip' => false,
            'treatment_mode' => TreatmentMode::Regular->value,
            'base_choice_code' => 'nezuko-regular-90',
            'base_duration_minutes' => 90,
            'base_price_idr' => 265000,
            'extension_choice_code' => 'nezuko-regular-extra-60',
            'extension_duration_minutes' => 60,
            'extension_price_idr' => 75000,
            'total_duration_minutes' => 150,
            'total_price_idr' => 340000,
        ]);

        $this->assertTrue($user->bookings->contains($booking));
        $this->assertTrue($booking->user->is($user));
        $this->assertTrue($booking->service->is($service));
        $this->assertTrue($booking->serviceVariant->is($variant));
        $this->assertTrue($booking->serviceExtension->is($extension));

        $service->delete();
        $user->delete();

        $booking = $booking->fresh();

        $this->assertNull($booking->user_id);
        $this->assertNull($booking->service_id);
        $this->assertNull($booking->service_variant_id);
        $this->assertNull($booking->service_extension_id);
        $this->assertSame('nezuko', $booking->package_slug);
        $this->assertSame('Nezuko Lama', $booking->package_name_id);
        $this->assertSame('Old Nezuko', $booking->package_name_en);
        $this->assertSame('nezuko-regular-90', $booking->base_choice_code);
        $this->assertSame('nezuko-regular-extra-60', $booking->extension_choice_code);
        $this->assertSame(340000, $booking->total_price_idr);
    }

    // Production break caught: non-unique public references would make customer booking detail routes ambiguous.
    public function test_booking_reference_is_unique(): void
    {
        Booking::factory()->create(['reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL00']);

        $duplicate = null;

        try {
            Booking::factory()->create(['reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL00']);
        } catch (QueryException $exception) {
            $duplicate = $exception;
        }

        $this->assertInstanceOf(QueryException::class, $duplicate);
    }

    // Production break caught: idempotency keys must dedupe per customer without leaking records across customers.
    public function test_submission_key_is_unique_per_user_only(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $submissionKey = '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10';

        Booking::factory()->for($firstUser)->create(['submission_key' => $submissionKey]);
        Booking::factory()->for($secondUser)->create(['submission_key' => $submissionKey]);

        $duplicateForSameUser = null;

        try {
            Booking::factory()->for($firstUser)->create(['submission_key' => $submissionKey]);
        } catch (QueryException $exception) {
            $duplicateForSameUser = $exception;
        }

        $this->assertSame(2, Booking::query()->where('submission_key', $submissionKey)->count());
        $this->assertInstanceOf(QueryException::class, $duplicateForSameUser);
    }

    /**
     * @return list<string>
     */
    private function expectedColumns(): array
    {
        return [
            'id',
            'user_id',
            'service_id',
            'service_variant_id',
            'service_extension_id',
            'reference',
            'submission_key',
            'request_fingerprint',
            'status',
            'customer_name',
            'customer_email',
            'customer_phone',
            'appointment_date',
            'appointment_time',
            'appointment_timezone',
            'guests',
            'notes',
            'package_slug',
            'package_name_id',
            'package_name_en',
            'package_is_vip',
            'treatment_mode',
            'base_choice_code',
            'base_duration_minutes',
            'base_price_idr',
            'extension_choice_code',
            'extension_duration_minutes',
            'extension_price_idr',
            'total_duration_minutes',
            'total_price_idr',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function expectedStatusChangeColumns(): array
    {
        return [
            'id',
            'booking_id',
            'admin_user_id',
            'actor_name',
            'actor_email',
            'from_status',
            'to_status',
            'reason',
            'ip_address',
            'user_agent',
            'created_at',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validBookingRow(array $overrides = []): array
    {
        return [
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL01',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a11',
            'request_fingerprint' => hash('sha256', 'booking-schema-test'),
            'status' => BookingStatus::Pending->value,
            'customer_name' => 'Dewi Lestari',
            'customer_email' => 'dewi@example.test',
            'customer_phone' => '+6281234567890',
            'appointment_date' => '2026-09-15',
            'appointment_time' => '10:30',
            'appointment_timezone' => 'Asia/Jakarta',
            'guests' => 1,
            'package_slug' => 'nezuko',
            'package_name_id' => 'Nezuko',
            'package_name_en' => 'Nezuko',
            'package_is_vip' => false,
            'treatment_mode' => TreatmentMode::Regular->value,
            'base_choice_code' => 'nezuko-regular-90',
            'base_duration_minutes' => 90,
            'base_price_idr' => 265000,
            'total_duration_minutes' => 90,
            'total_price_idr' => 265000,
            ...$overrides,
        ];
    }

    /**
     * @return array{0: User, 1: Service, 2: ServiceVariant, 3: ServiceExtension}
     */
    private function createCatalogBookingOwners(): array
    {
        $user = User::factory()->create();
        $category = ServiceCategory::query()->create([
            'slug' => 'package',
            'name_id' => 'Paket Spa',
            'name_en' => 'Spa Packages',
            'image_path' => '/img/package.jpg',
            'is_active' => true,
            'display_order' => 1,
        ]);
        $service = Service::query()->create([
            'category_id' => $category->id,
            'slug' => 'nezuko',
            'name_id' => 'Nezuko',
            'name_en' => 'Nezuko',
            'description_id' => 'Deskripsi',
            'description_en' => 'Description',
            'long_description_id' => 'Deskripsi lengkap',
            'long_description_en' => 'Full description',
            'good_for_id' => 'Relaksasi',
            'good_for_en' => 'Relaxation',
            'duration_minutes' => null,
            'price_idr' => null,
            'image_path' => '/img/massage.jpg',
            'gallery_paths' => ['massage.jpg'],
            'popularity' => 100,
            'is_vip' => false,
            'is_active' => true,
            'display_order' => 1,
        ]);
        $variant = ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => 'nezuko-regular-90',
            'treatment_mode' => TreatmentMode::Regular,
            'duration_minutes' => 90,
            'price_idr' => 265000,
            'is_active' => true,
            'display_order' => 1,
        ]);
        $extension = ServiceExtension::query()->create([
            'service_id' => $service->id,
            'code' => 'nezuko-regular-extra-60',
            'treatment_mode' => TreatmentMode::Regular,
            'duration_minutes' => 60,
            'price_idr' => 75000,
            'is_active' => true,
            'display_order' => 1,
        ]);

        return [$user, $service, $variant, $extension];
    }
}
