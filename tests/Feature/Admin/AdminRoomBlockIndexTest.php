<?php

namespace Tests\Feature\Admin;

use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminRoomBlockIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_paginated_list_exposes_eleventh_and_far_future_blocks_without_contact_data(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 00:00 UTC');
        $room = Room::create(['code' => 'FIRST', 'name' => 'First', 'capacity' => 2]);
        $other = Room::create(['code' => 'SECOND', 'name' => 'Second', 'capacity' => 2]);
        foreach (range(1, 25) as $i) {
            RoomBlock::create(['room_id' => $room->id, 'starts_at' => CarbonImmutable::now('UTC')->addDays(60 + $i), 'ends_at' => CarbonImmutable::now('UTC')->addDays(60 + $i)->addHour(), 'reason' => 'Private repair '.$i]);
        }
        RoomBlock::create(['room_id' => $other->id, 'starts_at' => CarbonImmutable::now('UTC')->addDays(90), 'ends_at' => CarbonImmutable::now('UTC')->addDays(91), 'reason' => 'Other']);
        $staff = User::factory()->admin()->create(['can_manage_bookings' => false]);
        $this->actingAs($staff)->get('/admin/room-blocks?roomId='.$room->id)->assertOk()->assertInertia(fn ($p) => $p->component('admin/rooms/Blocks')->has('schedule.blocks', 20)->where('schedule.blocks.10.reason', 'Private repair 11')->where('schedule.pagination.total', 25)->where('schedule.blockManagement.canManageBlocks', false)->missing('schedule.blocks.0.createdBy')->missing('schedule.blocks.0.customer'));
        $this->get('/admin/room-blocks?roomId='.$room->id.'&page=2')->assertOk()->assertInertia(fn ($p) => $p->has('schedule.blocks', 5)->where('schedule.blocks.4.reason', 'Private repair 25')->where('schedule.pagination.previous', '/admin/room-blocks?roomId='.$room->id.'&view=active&page=1'));
        foreach (['roomId[]=1', 'roomId=01', 'page=-1', 'page[]=2'] as $bad) {
            $this->get('/admin/room-blocks?'.$bad)->assertRedirect()->assertSessionHasErrors();
        }
        CarbonImmutable::setTestNow();
    }

    public function test_access_is_fresh_and_independent_of_inventory_ownership(): void
    {
        foreach ([['owner', false, true], ['admin', false, false], ['admin', true, true]] as [$role, $capability, $allowed]) {
            $actor = User::factory()->create(['role' => $role, 'can_manage_bookings' => $capability]);
            $this->actingAs($actor)->get('/admin/rooms')->assertOk()->assertInertia(fn ($p) => $p->where('blockManagement.canManageBlocks', $allowed));
            $response = $this->get('/admin/room-blocks/create');
            if ($allowed) {
                $response->assertOk()->assertInertia(fn ($p) => $p->where('blockManagement.ready', true)->where('block', null));
            } else {
                $response->assertForbidden();
            }
            DB::table('users')->where('id', $actor->id)->update(['can_manage_bookings' => false, 'role' => 'admin']);
            $this->get('/admin/room-blocks/create')->assertForbidden();
        }
    }

    public function test_partial_schema_remains_readable_but_forms_fail_closed_and_do_not_leak_creator(): void
    {
        $owner = User::factory()->owner()->create();
        $room = Room::create(['code' => 'R', 'name' => 'Room', 'capacity' => 1]);
        $block = RoomBlock::create(['room_id' => $room->id, 'starts_at' => '2030-01-01 03:00:00', 'ends_at' => '2030-01-01 04:00:00', 'reason' => 'Repair', 'created_by' => $owner->id]);
        $this->actingAs($owner)->get('/admin/room-blocks/'.$block->id.'/edit')->assertOk()->assertInertia(fn ($p) => $p->where('block.startsAtInput', '2030-01-01T10:00')->where('block.version', 1)->missing('block.created_by'));
        Schema::table('room_reservations', fn ($b) => $b->renameColumn('released_at', 'b3f_missing'));
        try {
            $this->get('/admin/room-blocks/create')->assertOk()->assertInertia(fn ($p) => $p->where('blockManagement.ready', false)->where('rooms', [])->where('catalog.packages', []));
        } finally {
            Schema::table('room_reservations', fn ($b) => $b->renameColumn('b3f_missing', 'released_at'));
        }
        Schema::table('users', fn ($b) => $b->renameColumn('can_manage_bookings', 'b3f_missing'));
        try {
            $this->get('/admin/room-blocks')->assertOk()->assertInertia(fn ($p) => $p->where('schedule.blockManagement.ready', false));
            $this->get('/admin/rooms')->assertOk()->assertInertia(fn ($p) => $p->where('blockManagement.canManageBlocks', false));
            $this->get('/admin/room-blocks/create')->assertForbidden();
        } finally {
            Schema::table('users', fn ($b) => $b->renameColumn('b3f_missing', 'can_manage_bookings'));
        }
    }
}
