<?php

namespace Tests\Feature\Admin;

use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Queries\AdminRoomIndexQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminRoomIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24T03:00:00.987Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_only_current_verified_staff_can_read_inventory(): void
    {
        $this->get('/admin/rooms')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/rooms')->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create())
            ->get('/admin/rooms')->assertRedirect(route('verification.notice', absolute: false));
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/admin/rooms')->assertOk()->assertInertia(fn ($page) => $page->where('blockManagement.canManageBlocks', false)->where('blockManagement.ready', true));
        DB::table('users')->where('id', $admin->id)->update(['role' => 'customer']);
        $this->actingAs($admin)->get('/admin/rooms')->assertForbidden();
    }

    public function test_ready_empty_has_explicit_observation_and_default_off_flag(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())->get('/admin/rooms');
        $response->assertOk();
        $this->assertSame([
            'state' => 'ready', 'generatedAt' => '2026-10-24T03:00:00Z',
            'blocksUntil' => '2026-11-23T03:00:00Z', 'schedulingEnabled' => false, 'rooms' => [],
        ], $response->inertiaProps('inventory'));
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_safe_payload_retains_disabled_rooms_and_inactive_catalog(): void
    {
        $room = $this->room('Z', false);
        $category = ServiceCategory::create(['slug' => 'room-category', 'name_id' => 'Kategori', 'name_en' => 'Category', 'display_order' => 0, 'is_active' => false, 'published_at' => now()]);
        $service = Service::create(['category_id' => $category->id, 'slug' => 'room-service', 'name_id' => 'Pijat', 'name_en' => 'Massage', 'display_order' => 0, 'price_idr' => 999, 'is_active' => false]);
        $variant = ServiceVariant::create(['service_id' => $service->id, 'code' => 'ROOM-VARIANT', 'treatment_mode' => 'double_therapist', 'duration_minutes' => 90, 'display_order' => 0, 'price_idr' => 999, 'is_active' => false, 'published_at' => now()]);
        $room->variants()->attach($variant);
        $this->block($room, '2026-10-24 02:00:00', '2026-10-24 04:00:00');
        $this->room('A');
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $inventory = (new AdminRoomIndexQuery)->get();
        $this->assertSame([], $writes);
        $this->assertSame(['A', 'Z'], array_column($inventory['rooms'], 'code'));
        $data = $inventory['rooms'][1];
        $this->assertSame(['id', 'version', 'code', 'name', 'type', 'capacity', 'cleaningMinutes', 'isActive', 'compatibleVariants', 'currentBlockCount', 'upcomingBlockCount', 'blocks'], array_keys($data));
        $this->assertSame(1, $data['version']);
        $this->assertFalse($data['isActive']);
        $this->assertSame([
            'id' => $variant->id, 'code' => 'ROOM-VARIANT', 'serviceNameId' => 'Pijat', 'serviceNameEn' => 'Massage',
            'treatmentMode' => 'double_therapist', 'durationMinutes' => 90,
            'variantStatus' => 'archived', 'serviceStatus' => 'draft', 'categoryStatus' => 'archived',
        ], $data['compatibleVariants'][0]);
        $this->assertSame(['id', 'startsAt', 'endsAt', 'reason'], array_keys($data['blocks'][0]));
        $this->assertSame('2026-10-24T02:00:00Z', $data['blocks'][0]['startsAt']);
        $this->assertSame(1, $data['currentBlockCount']);
    }

    public function test_half_open_boundaries_full_counts_and_ten_blocks_per_room(): void
    {
        foreach (['A', 'B'] as $code) {
            $room = $this->room($code, false);
            $this->block($room, '2026-10-24 02:00:00', '2026-10-24 03:00:00'); // exact end excluded
            $this->block($room, '2026-10-24 03:00:00', '2026-12-24 04:00:00'); // exact start, long active
            $this->block($room, '2026-11-23 03:00:00', '2026-11-23 04:00:00'); // exact horizon excluded
            for ($i = 1; $i <= 12; $i++) {
                $this->block($room, '2026-10-25 03:00:'.sprintf('%02d', $i), '2026-10-25 04:00:00');
            }
        }
        foreach ((new AdminRoomIndexQuery)->get()['rooms'] as $room) {
            $this->assertCount(10, $room['blocks']);
            $this->assertSame(1, $room['currentBlockCount']);
            $this->assertSame(12, $room['upcomingBlockCount']);
            $this->assertSame('2026-10-24T03:00:00Z', $room['blocks'][0]['startsAt']);
            $this->assertSame('2026-10-25T03:00:09Z', $room['blocks'][9]['startsAt']);
        }
    }

    public function test_each_missing_dependency_is_unavailable_but_reservation_tables_are_not_required(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach (['rooms', 'room_service_variant', 'room_blocks', 'service_variants', 'services', 'service_categories'] as $table) {
            Schema::rename($table, $table.'_unavailable');
            try {
                $inventory = (new AdminRoomIndexQuery)->get();
                $this->assertSame('unavailable', $inventory['state'], $table);
                $this->assertSame([], $inventory['rooms']);
                $this->get('/admin/rooms')->assertOk()->assertInertia(fn ($page) => $page
                    ->where('inventory.state', 'unavailable')->where('inventory.rooms', []));
            } finally {
                Schema::rename($table.'_unavailable', $table);
            }
        }
        Schema::rename('room_reservations', 'room_reservations_unused');
        try {
            $this->assertSame('ready', (new AdminRoomIndexQuery)->get()['state']);
        } finally {
            Schema::rename('room_reservations_unused', 'room_reservations');
        }
    }

    public function test_query_count_is_batched_and_database_errors_propagate(): void
    {
        $category = ServiceCategory::create(['slug' => 'batch', 'name_id' => 'Kategori', 'name_en' => 'Category', 'image_path' => '', 'display_order' => 0]);
        $service = Service::create(['category_id' => $category->id, 'slug' => 'batch', 'name_id' => 'Pijat', 'name_en' => 'Massage', 'description_id' => '', 'description_en' => '', 'long_description_id' => '', 'long_description_en' => '', 'image_path' => '', 'gallery_paths' => [], 'popularity' => 0, 'display_order' => 0]);
        $variant = ServiceVariant::create(['service_id' => $service->id, 'code' => 'batch', 'treatment_mode' => 'regular', 'duration_minutes' => 60, 'price_idr' => 1, 'display_order' => 0]);
        $firstRoom = $this->room('A');
        $firstRoom->variants()->attach($variant);
        $this->block($firstRoom, '2026-10-24 02:00:00', '2026-10-24 04:00:00');
        $count = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            (new AdminRoomIndexQuery)->get();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $first = $count();
        foreach (range(1, 20) as $i) {
            $room = $this->room('B'.$i);
            $room->variants()->attach($variant);
            $this->block($room, '2026-10-24 02:00:00', '2026-10-24 04:00:00');
        }
        $this->assertSame($first, $count());
        Schema::table('room_blocks', fn ($table) => $table->renameColumn('ends_at', 'broken_end'));
        try {
            $this->expectException(QueryException::class);
            (new AdminRoomIndexQuery)->get();
        } finally {
            Schema::table('room_blocks', fn ($table) => $table->renameColumn('broken_end', 'ends_at'));
        }
    }

    public function test_missing_room_version_makes_owner_inventory_unavailable_with_empty_shared_catalog(): void
    {
        $this->room('VERSION');
        $owner = User::factory()->owner()->create();
        Schema::table('rooms', fn ($table) => $table->renameColumn('version', 'missing_version'));
        try {
            $this->actingAs($owner)->get('/admin/rooms')->assertOk()->assertInertia(fn ($page) => $page
                ->where('inventory.state', 'unavailable')->where('inventory.rooms', [])
                ->where('management.isOwner', true)->where('management.ready', false)
                ->where('catalog.packages', []));
        } finally {
            Schema::table('rooms', fn ($table) => $table->renameColumn('missing_version', 'version'));
        }
    }

    public function test_raw_stored_utc_is_not_reinterpreted_by_app_timezone(): void
    {
        $room = $this->room('UTC');
        $this->block($room, '2026-10-24 02:00:00', '2026-10-24 04:00:00');
        config(['app.timezone' => 'Asia/Jakarta']);
        $data = (new AdminRoomIndexQuery)->get()['rooms'][0];
        $this->assertSame('2026-10-24T02:00:00Z', $data['blocks'][0]['startsAt']);
        $this->assertSame(1, $data['currentBlockCount']);
    }

    public function test_catalog_parent_deleted_during_read_does_not_invent_a_relationship_or_crash(): void
    {
        $category = ServiceCategory::create(['slug' => 'deleted', 'name_id' => 'Deleted', 'name_en' => 'Deleted', 'display_order' => 0]);
        $service = Service::create(['category_id' => $category->id, 'slug' => 'deleted', 'name_id' => 'Deleted', 'name_en' => 'Deleted', 'display_order' => 0]);
        $variant = ServiceVariant::create(['service_id' => $service->id, 'code' => 'deleted', 'treatment_mode' => 'regular', 'duration_minutes' => 60, 'price_idr' => 1, 'display_order' => 0]);
        $room = $this->room('deleted');
        $room->variants()->attach($variant);
        $deleted = false;
        DB::listen(function ($query) use (&$deleted, $variant, $service): void {
            if (! $deleted && str_contains($query->sql, 'inner join "room_service_variant"')) {
                $deleted = true;
                $variant->delete();
                $service->delete();
            }
        });
        $inventory = (new AdminRoomIndexQuery)->get();
        $this->assertTrue($deleted);
        $this->assertSame([], $inventory['rooms'][0]['compatibleVariants']);
    }

    private function room(string $code, bool $enabled = true): Room
    {
        return Room::create(['code' => $code, 'name' => 'Room '.$code, 'capacity' => 2, 'is_active' => $enabled]);
    }

    private function block(Room $room, string $start, string $end): void
    {
        RoomBlock::create(['room_id' => $room->id, 'starts_at' => $start, 'ends_at' => $end, 'reason' => '<script>staff-only</script>']);
    }
}
