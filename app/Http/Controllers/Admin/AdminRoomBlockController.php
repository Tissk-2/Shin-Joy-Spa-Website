<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveRoomBlock;
use App\Actions\TerminateRoomBlock;
use App\Http\Controllers\Controller;
use App\Queries\RoomBlockManagementQuery;
use App\Support\Rooms\RoomBlockActor;
use App\Support\Rooms\RoomInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

final class AdminRoomBlockController extends Controller
{
    public function index(Request $request, RoomBlockManagementQuery $query): Response
    {
        $schedule = $query->index($request->user(), $request->only(['roomId', 'page', 'view']));

        return inertia('admin/rooms/Blocks', ['schedule' => $schedule, ...isset($schedule['catalog']) ? ['catalog' => $schedule['catalog']] : []]);
    }

    public function create(Request $request, RoomBlockManagementQuery $query): Response
    {
        return inertia('admin/rooms/BlockForm', $query->form($request->user(), null));
    }

    public function edit(Request $request, string $block, RoomBlockManagementQuery $query): Response
    {
        return inertia('admin/rooms/BlockForm', $query->form($request->user(), $this->identifier($block)));
    }

    public function store(Request $request, SaveRoomBlock $save): RedirectResponse
    {
        (new RoomBlockActor)->authorize($request->user());
        $data = $request->except(['_token', '_method']);
        $scope = $data['scope'] ?? null;
        unset($data['scope']);
        if ($scope === 'all') {
            $blocks = $save->forAllRooms($request->user(), $data);

            return to_route('admin.room-blocks.index')->with('status', __('admin.blocks.all_saved', ['count' => count($blocks)]));
        }
        if ($scope !== 'room') {
            throw ValidationException::withMessages(['scope' => __('admin.blocks.invalid')]);
        }
        $block = $save($request->user(), null, $data);

        return to_route('admin.room-blocks.edit', ['block' => $block->id])->with('status', __('admin.blocks.saved'));
    }

    public function update(Request $request, string $block, SaveRoomBlock $save): RedirectResponse
    {
        (new RoomBlockActor)->authorize($request->user());
        $saved = $save($request->user(), $this->identifier($block), $request->except(['_token', '_method']));

        return to_route('admin.room-blocks.edit', ['block' => $saved->id])->with('status', __('admin.blocks.saved'));
    }

    private function identifier(string $value): int
    {
        abort_unless(RoomInput::integer($value, 1, PHP_INT_MAX), 404);

        return (int) $value;
    }

    public function terminate(Request $request, string $block, TerminateRoomBlock $terminate): RedirectResponse
    {
        (new RoomBlockActor)->authorize($request->user());
        $saved = $terminate($request->user(), $this->identifier($block), $request->except(['_token', '_method']));

        return to_route('admin.room-blocks.edit', ['block' => $saved->id])->with('status', __('admin.blocks.termination_saved'));
    }
}
