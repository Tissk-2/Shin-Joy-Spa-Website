<?php

namespace App\Queries;

use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use App\Support\Rooms\RoomBlockActor;
use App\Support\Rooms\RoomBlockInterval;
use App\Support\Rooms\RoomBlockTerminationSchema;
use App\Support\Rooms\RoomInput;
use App\Support\Rooms\RoomWriteSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class RoomBlockManagementQuery
{
    public function access(?User $actor): array
    {
        $capable = (new RoomBlockActor)->current($actor) !== null;
        $ready = (new RoomWriteSchema)->ready();
        $terminationReady = $ready && (new RoomBlockTerminationSchema)->mode() === 'complete';

        return ['canManageBlocks' => $capable, 'ready' => $ready,
            'canTerminateBlocks' => $capable, 'terminationReady' => $terminationReady];
    }

    public function index(?User $actor, array $validatedFilters): array
    {
        $view = $validatedFilters['view'] ?? 'active';
        if (! in_array($view, ['active', 'history'], true)) {
            throw ValidationException::withMessages(['view' => __('admin.blocks.invalid')]);
        }
        foreach (array_diff_key($validatedFilters, ['view' => true]) as $key => $value) {
            if (! in_array($key, ['roomId', 'page'], true) || ! RoomInput::integer($value, 1, $key === 'page' ? intdiv(PHP_INT_MAX, 20) : PHP_INT_MAX)) {
                throw ValidationException::withMessages([$key => __('admin.blocks.invalid')]);
            }
        }
        $roomId = isset($validatedFilters['roomId']) ? (int) $validatedFilters['roomId'] : null;
        $page = isset($validatedFilters['page']) ? (int) $validatedFilters['page'] : 1;
        $now = CarbonImmutable::now('UTC');
        $access = $this->access($actor);
        $mode = (new RoomBlockTerminationSchema)->mode();
        $readable = $mode !== 'partial' && Schema::hasColumns('room_blocks', ['id', 'room_id', 'starts_at', 'ends_at', 'reason', 'version'])
            && Schema::hasColumns('rooms', ['id', 'code', 'name', 'is_active']);
        $base = $readable ? RoomBlock::query()->when($roomId !== null, fn ($q) => $q->where('room_id', $roomId)) : null;
        if ($base !== null && $mode === 'complete') {
            // Validate terminal facts before SQL excludes them from active pages.
            // Chunking keeps history/far-future access bounded in memory.
            (clone $base)->whereExists(fn ($q) => $q->selectRaw('1')->from('room_block_terminations')
                ->whereColumn('room_block_id', 'room_blocks.id'))->chunkById(200, function ($blocks): void {
                    (new RoomBlockInterval)->loadTerminations($blocks->modelKeys());
                });
        }
        $query = $base?->where(function ($q) use ($view, $now, $mode): void {
            $q->where('ends_at', $view === 'history' ? '<=' : '>', $now->format('Y-m-d H:i:s.u'));
            if ($mode === 'complete') {
                $terminal = fn ($t) => $t->selectRaw('1')->from('room_block_terminations')->whereColumn('room_block_id', 'room_blocks.id');
                $view === 'history' ? $q->orWhereExists($terminal) : $q->whereNotExists($terminal);
            }
        })->orderBy('starts_at')->orderBy('id');
        $total = $query?->count() ?? 0;
        $rooms = $readable ? $this->rooms() : [];
        $codes = array_column($rooms, 'code', 'id');
        $blocks = $query?->offset(($page - 1) * 20)->limit(20)->get() ?? collect();
        $terminal = $readable ? (new RoomBlockInterval)->loadTerminations($blocks->pluck('id')->all()) : [];
        $url = fn (int $number) => '/admin/room-blocks?'.http_build_query([...($roomId === null ? [] : ['roomId' => $roomId]), 'view' => $view, 'page' => $number]);

        return ['state' => $readable ? 'ready' : 'unavailable', 'generatedAt' => $now->toIso8601ZuluString(), 'view' => $view,
            'rooms' => $rooms, 'roomId' => $roomId, 'blockManagement' => $access,
            'blocks' => $blocks->map(fn ($b) => [...$this->present($b, $now, $terminal[$b->id] ?? null), 'roomCode' => $codes[$b->room_id] ?? '#'.$b->room_id])->all(),
            'pagination' => ['page' => $page, 'lastPage' => max(1, (int) ceil($total / 20)), 'total' => $total,
                'previous' => $page > 1 ? $url($page - 1) : null, 'next' => $page * 20 < $total ? $url($page + 1) : null],
            ...(! $access['ready'] ? ['catalog' => ['packages' => []]] : [])];
    }

    public function form(User $actor, ?int $blockId): array
    {
        (new RoomBlockActor)->authorize($actor);
        $access = $this->access($actor);
        if (! $access['ready']) {
            return ['blockManagement' => $access, 'block' => null, 'rooms' => [], 'catalog' => ['packages' => []]];
        }
        $block = $blockId === null ? null : RoomBlock::findOrFail($blockId);
        $terminal = (new RoomBlockInterval)->loadTerminations($block ? [$block->id] : []);

        return ['blockManagement' => $access, 'block' => $block ? $this->present($block, CarbonImmutable::now('UTC'), $terminal[$block->id] ?? null) : null, 'rooms' => $this->rooms()];
    }

    private function rooms(): array
    {
        return Room::query()->orderBy('code')->orderBy('id')->get(['id', 'code', 'name', 'is_active'])->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'isActive' => $r->is_active])->all();
    }

    private function present(RoomBlock $block, CarbonImmutable $now, ?object $terminal): array
    {
        $interval = new RoomBlockInterval;
        $start = $interval->instant($block, 'starts_at');
        $end = $interval->instant($block, 'ends_at');
        $effectiveEnd = $interval->effectiveEnd($block, $terminal);

        return ['id' => $block->id, 'roomId' => $block->room_id, 'version' => $block->version, 'reason' => $block->reason,
            'startsAt' => $start->format('Y-m-d\TH:i:s.u\Z'), 'endsAt' => $end->format('Y-m-d\TH:i:s.u\Z'),
            'effectiveEndsAt' => $effectiveEnd->format('Y-m-d\TH:i:s.u\Z'),
            'termination' => $terminal ? ['kind' => $terminal->kind, 'occurredAt' => CarbonImmutable::parse($terminal->occurred_at, 'UTC')->format('Y-m-d\TH:i:s.u\Z'),
                'reason' => $terminal->reason, 'actorName' => $terminal->actor_name, 'actorRole' => $terminal->actor_role,
                'previousVersion' => (int) $terminal->previous_version, 'resultingVersion' => (int) $terminal->resulting_version] : null,
            'startsAtInput' => $start->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i'), 'endsAtInput' => $end->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i'),
            'state' => $terminal ? ($terminal->kind === 'cancel_future' ? 'cancelled' : 'ended_early') : ($end <= $now ? 'ended' : ($start <= $now ? 'active' : 'future'))];
    }
}
