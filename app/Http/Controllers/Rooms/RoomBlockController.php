<?php

namespace App\Http\Controllers\Rooms;

use App\Actions\Rooms\CancelRoomBlock;
use App\Actions\Rooms\CreateRoomBlock;
use App\Actions\Rooms\EndRoomBlock;
use App\Actions\Rooms\UpdateRoomBlock;
use App\Data\Rooms\RoomBlockData;
use App\Data\Rooms\RoomBlockRoomData;
use App\Data\Rooms\RoomGroupData;
use App\Enums\RoomBlockReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rooms\EndRoomBlockRequest;
use App\Http\Requests\Rooms\RoomBlockRequest;
use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomGroup;
use App\Services\Property\PropertyToday;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoomBlockController extends Controller
{
    /**
     * How many ended blocks the Past tab shows, newest first.
     */
    private const PAST_LIMIT = 50;

    /**
     * Load every current and upcoming block, the latest ended ones, and the rooms that can be blocked.
     */
    public function index(Property $property, PropertyToday $propertyToday): Response
    {
        $todayDate = $propertyToday->date($property);
        $today = $todayDate->toDateString();
        $timeZone = $todayDate->getTimezone()->getName();
        $relations = ['room.roomType', 'bed'];

        $current = $property->roomBlocks()->with($relations)->open()
            ->whereDate('ends_on', '>=', $today)
            ->orderBy('starts_on')->orderBy('id')
            ->get();

        $past = $property->roomBlocks()->with($relations)
            ->where(fn (Builder $query) => $query->whereNotNull('completed_at')->orWhereDate('ends_on', '<', $today))
            ->orderByDesc('ends_on')->orderByDesc('id')
            ->limit(self::PAST_LIMIT)
            ->get();

        return Inertia::render('front-desk/room-blocks/index', [
            'blocks' => $current->concat($past)->map(fn (RoomBlock $block): RoomBlockData => RoomBlockData::fromModel($block, $timeZone))->values(),
            'rooms' => $property->rooms()->active()->ordered()
                ->with(['roomType', 'beds' => fn ($query) => $query->active()->ordered()])
                ->get()
                ->map(fn (Room $room): RoomBlockRoomData => RoomBlockRoomData::fromModel($room)),
            'roomGroups' => $property->roomGroups()->orderBy('position')->orderBy('id')->get()
                ->map(fn (RoomGroup $group): RoomGroupData => RoomGroupData::fromModel($group)),
            'reasons' => RoomBlockReason::options(),
            'today' => $today,
            'maxNights' => RoomBlockRequest::MAX_NIGHTS,
            'pastLimit' => self::PAST_LIMIT,
        ]);
    }

    public function store(RoomBlockRequest $request, Property $property, CreateRoomBlock $createRoomBlock): RedirectResponse
    {
        $room = $property->rooms()->findOrFail($request->integer('room_id'));
        $bed = $request->validated('bed_id') !== null ? $room->beds()->findOrFail($request->integer('bed_id')) : null;

        $block = $createRoomBlock($request->user(), $room, $bed, $request->toRoomBlockData());

        Inertia::flash('toast', $block
            ? ['type' => 'success', 'message' => __(':unit blocked.', ['unit' => $this->unitName($room, $bed)])]
            : ['type' => 'error', 'message' => __(':unit is already blocked for some of these nights.', ['unit' => $this->unitName($room, $bed)])]);

        return to_route('front-desk.room-blocks.index', $property);
    }

    public function update(RoomBlockRequest $request, Property $property, RoomBlock $roomBlock, UpdateRoomBlock $updateRoomBlock): RedirectResponse
    {
        $unit = $this->unitName($roomBlock->room, $roomBlock->bed);

        Inertia::flash('toast', $updateRoomBlock($request->user(), $roomBlock, $request->toRoomBlockData())
            ? ['type' => 'success', 'message' => __('Block for :unit saved.', ['unit' => $unit])]
            : ['type' => 'error', 'message' => __('Block for :unit was not saved. It has ended, or another block covers some of these nights.', ['unit' => $unit])]);

        return to_route('front-desk.room-blocks.index', $property);
    }

    public function end(EndRoomBlockRequest $request, Property $property, RoomBlock $roomBlock, EndRoomBlock $endRoomBlock): RedirectResponse
    {
        $unit = $this->unitName($roomBlock->room, $roomBlock->bed);

        Inertia::flash('toast', $endRoomBlock($request->user(), $roomBlock, $request->roomStatus())
            ? ['type' => 'success', 'message' => __(':unit is back in service.', ['unit' => $unit])]
            : ['type' => 'error', 'message' => __('Block for :unit cannot be ended. Only a block that is running today can end.', ['unit' => $unit])]);

        return to_route('front-desk.room-blocks.index', $property);
    }

    public function destroy(Request $request, Property $property, RoomBlock $roomBlock, CancelRoomBlock $cancelRoomBlock): RedirectResponse
    {
        $unit = $this->unitName($roomBlock->room, $roomBlock->bed);

        Inertia::flash('toast', $cancelRoomBlock($request->user(), $roomBlock)
            ? ['type' => 'success', 'message' => __('Block for :unit cancelled.', ['unit' => $unit])]
            : ['type' => 'error', 'message' => __('Block for :unit cannot be cancelled because it has started. End it instead.', ['unit' => $unit])]);

        return to_route('front-desk.room-blocks.index', $property);
    }

    /**
     * "Room 203" or "Room 12, bed A3", for toasts.
     */
    private function unitName(Room $room, ?Bed $bed): string
    {
        return $bed === null
            ? __('Room :number', ['number' => $room->number])
            : __('Room :number, bed :label', ['number' => $room->number, 'label' => $bed->label]);
    }
}
