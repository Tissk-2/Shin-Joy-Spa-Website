<?php

namespace Tests\Feature\Scheduling;

use App\Actions\TerminateRoomBlock;
use App\Models\RoomBlockTermination;
use App\Support\Rooms\RoomBlockTerminationSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Admin\TerminateRoomBlockTest;
use Tests\TestCase;

class RoomBlockTerminationMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_additive_migration_preserves_blocks_and_empty_rollback(): void
    {
        [$actor, $block] = (new TerminateRoomBlockTest('fixture'))->fixture();
        $migration = require database_path('migrations/2026_10_25_010007_add_room_block_terminations.php');
        $before = DB::table('room_blocks')->get()->toJson();
        $indexes = Schema::getIndexes('room_blocks');
        $foreign = Schema::getForeignKeys('room_blocks');
        $migration->down();
        $this->assertSame('absent', (new RoomBlockTerminationSchema)->mode());
        $migration->up();
        $this->assertSame('complete', (new RoomBlockTerminationSchema)->mode());
        $this->assertSame($before, DB::table('room_blocks')->get()->toJson());
        $this->assertSame($indexes, Schema::getIndexes('room_blocks'));
        $this->assertSame($foreign, Schema::getForeignKeys('room_blocks'));
        foreach (['kind', 'actor_role', 'original_ends_at', 'resulting_version'] as $column) {
            Schema::table('room_block_terminations', fn ($t) => $t->renameColumn($column, 'missing'));
            $this->assertSame('partial', (new RoomBlockTerminationSchema)->mode());
            Schema::table('room_block_terminations', fn ($t) => $t->renameColumn('missing', $column));
        }
    }

    public function test_terminal_evidence_blocks_rollback_and_delete_but_actor_snapshot_survives(): void
    {
        [$actor, $block] = (new TerminateRoomBlockTest('fixture'))->fixture();
        (new TerminateRoomBlock)($actor, $block->id, ['intent' => 'cancel_future', 'version' => 1, 'reason' => 'Done']);
        $row = DB::table('room_block_terminations')->first();
        $immutable = RoomBlockTermination::findOrFail($row->id);
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $immutable->update(['reason' => 'Rewrite']) : $immutable->delete();
                $this->fail('Terminal model allowed '.$operation);
            } catch (\LogicException) {
                $this->assertDatabaseHas('room_block_terminations', ['id' => $row->id, 'reason' => 'Done']);
            }
        }
        DB::table('users')->where('id', $actor->id)->delete();
        $this->assertDatabaseHas('room_block_terminations', ['actor_user_id' => null, 'actor_name' => $row->actor_name, 'actor_role' => 'owner']);
        try {
            $migration = require database_path('migrations/2026_10_25_010007_add_room_block_terminations.php');
            $migration->down();
            $this->fail('Rollback erased evidence');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('terminal', $error->getMessage());
            $this->assertTrue(Schema::hasTable('room_block_terminations'));
        }
        foreach (['duplicate', 'delete'] as $case) {
            try {
                if ($case === 'delete') {
                    DB::table('room_blocks')->where('id', $block->id)->delete();
                } else {
                    DB::table('room_block_terminations')->insert([...get_object_vars($row), 'id' => $row->id + 1, 'actor_user_id' => null]);
                }
                $this->fail($case.' accepted');
            } catch (QueryException) {
                $this->assertDatabaseCount('room_block_terminations', 1);
            }
        }
    }
}
