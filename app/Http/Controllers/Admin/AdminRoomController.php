<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveRoom;
use App\Http\Controllers\Controller;
use App\Queries\AdminRoomIndexQuery;
use App\Queries\RoomBlockManagementQuery;
use App\Queries\RoomManagementQuery;
use App\Support\Rooms\RoomOwner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

final class AdminRoomController extends Controller
{
    public function index(Request $request, AdminRoomIndexQuery $query, RoomManagementQuery $management): Response
    {
        $inventory = $query->get();

        return inertia('admin/rooms/Index', [
            'inventory' => $inventory,
            'management' => $management->access($request->user()),
            'blockManagement' => (new RoomBlockManagementQuery)->access($request->user()),
            // This staff screen does not consume the shared public catalog.
            ...($inventory['state'] === 'unavailable' ? ['catalog' => ['packages' => []]] : []),
        ]);
    }

    public function create(Request $request, RoomManagementQuery $query): Response
    {
        return inertia('admin/rooms/Form', $query->form($request->user(), null));
    }

    public function edit(Request $request, int $room, RoomManagementQuery $query): Response
    {
        return inertia('admin/rooms/Form', $query->form($request->user(), $room));
    }

    public function store(Request $request, SaveRoom $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function update(Request $request, int $room, SaveRoom $save): RedirectResponse
    {
        return $this->persist($request, $save, $room);
    }

    private function persist(Request $request, SaveRoom $save, ?int $id): RedirectResponse
    {
        // HTTP authorization runs before schema feedback and the action repeats it under the guard.
        (new RoomOwner)->authorize($request->user());
        $room = $save($request->user(), $id, $request->except(['_token', '_method']));

        return to_route('admin.rooms.edit', ['room' => $room->id])->with('status', __('admin.rooms.saved'));
    }
}
