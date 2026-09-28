<?php

namespace App\Http\Controllers\Rooms;

use App\Actions\Rooms\CreateRoomGroup;
use App\Actions\Rooms\DeleteRoomGroup;
use App\Actions\Rooms\ReorderRoomGroups;
use App\Actions\Rooms\UpdateRoomGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rooms\ReorderRoomGroupsRequest;
use App\Http\Requests\Rooms\RoomGroupRequest;
use App\Models\Property;
use App\Models\RoomGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RoomGroupController extends Controller
{
    public function store(RoomGroupRequest $request, Property $property, CreateRoomGroup $createRoomGroup): RedirectResponse
    {
        $group = $createRoomGroup($property, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created.', ['name' => $group->name])]);

        return to_route('settings.rooms.index', $property);
    }

    public function update(RoomGroupRequest $request, Property $property, RoomGroup $roomGroup, UpdateRoomGroup $updateRoomGroup): RedirectResponse
    {
        $updateRoomGroup($request->user(), $roomGroup, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $request->validated('name')])]);

        return to_route('settings.rooms.index', $property);
    }

    public function reorder(ReorderRoomGroupsRequest $request, Property $property, ReorderRoomGroups $reorderRoomGroups): RedirectResponse
    {
        if (! $reorderRoomGroups($property, $request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The room groups changed. Reload the page and try again.')]);
        }

        return to_route('settings.rooms.index', $property);
    }

    public function destroy(Request $request, Property $property, RoomGroup $roomGroup, DeleteRoomGroup $deleteRoomGroup): RedirectResponse
    {
        Inertia::flash('toast', $deleteRoomGroup($request->user(), $roomGroup)
            ? ['type' => 'success', 'message' => __(':name deleted.', ['name' => $roomGroup->name])]
            : ['type' => 'error', 'message' => __(':name still has rooms. Move or archive them first.', ['name' => $roomGroup->name])]);

        return to_route('settings.rooms.index', $property);
    }
}
