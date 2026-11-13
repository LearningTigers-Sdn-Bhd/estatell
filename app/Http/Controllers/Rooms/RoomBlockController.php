<?php

namespace App\Http\Controllers\Rooms;

use App\Actions\Rooms\CancelRoomBlock;
use App\Actions\Rooms\CancelRoomBlocks;
use App\Actions\Rooms\CreateRoomBlocks;
use App\Actions\Rooms\EndRoomBlock;
use App\Actions\Rooms\EndRoomBlocks;
use App\Actions\Rooms\RoomBlockBulkResult;
use App\Actions\Rooms\RoomBlockRefused;
use App\Actions\Rooms\UpdateRoomBlock;
use App\Data\Rooms\RoomBlockData;
use App\Data\Rooms\RoomBlockRoomData;
use App\Data\Rooms\RoomGroupData;
use App\Enums\RoomBlockReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rooms\BulkEndRoomBlocksRequest;
use App\Http\Requests\Rooms\BulkRoomBlocksRequest;
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
     * Load every current and upcoming block, the latest ended ones, and the rooms that can be blocked. `?tab=past`
     * opens the Past tab; the browser filters the loaded blocks.
     */
    public function index(Request $request, Property $property, PropertyToday $propertyToday): Response
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
            'tab' => $request->string('tab')->toString() === 'past' ? 'past' : 'current',
        ]);
    }

    /**
     * Block one room (or one of its beds), or several whole rooms for the same nights.
     */
    public function store(RoomBlockRequest $request, Property $property, CreateRoomBlocks $createRoomBlocks): RedirectResponse
    {
        $ids = $request->roomIds();
        $rooms = $property->rooms()->whereIn('id', $ids)->ordered()->get();

        abort_if($rooms->count() !== count($ids), 404);

        $bed = $request->validated('bed_id') !== null ? $rooms->first()->beds()->findOrFail($request->integer('bed_id')) : null;
        $result = $createRoomBlocks($request->user(), $rooms, $bed, $request->toRoomBlockData());

        if ($rooms->count() === 1) {
            $unit = $this->unitName($rooms->first(), $bed);
            $refused = $result->skipped[0]['refused'] ?? null;

            Inertia::flash('toast', $refused instanceof RoomBlockRefused
                ? ['type' => 'error', 'message' => $refused->message($unit)]
                : ['type' => 'success', 'message' => __(':unit blocked.', ['unit' => $unit])]);
        } else {
            Inertia::flash('toast', $this->bulkToast(
                $result,
                trans_choice('{0} No rooms blocked.|{1} :count room blocked.|[2,*] :count rooms blocked.', $result->done),
                fn (array $skip): string => $skip['refused']?->message($this->unitName($skip['room'], $skip['bed'])) ?? '',
            ));
        }

        return back();
    }

    public function update(RoomBlockRequest $request, Property $property, RoomBlock $roomBlock, UpdateRoomBlock $updateRoomBlock): RedirectResponse
    {
        $unit = $this->unitName($roomBlock->room, $roomBlock->bed);

        $refusal = $updateRoomBlock($request->user(), $roomBlock, $request->toRoomBlockData());

        Inertia::flash('toast', $refusal === null
            ? ['type' => 'success', 'message' => __('Block for :unit saved.', ['unit' => $unit])]
            : ['type' => 'error', 'message' => $refusal->message($unit)]);

        return back();
    }

    public function end(EndRoomBlockRequest $request, Property $property, RoomBlock $roomBlock, EndRoomBlock $endRoomBlock): RedirectResponse
    {
        $unit = $this->unitName($roomBlock->room, $roomBlock->bed);

        Inertia::flash('toast', $endRoomBlock($request->user(), $roomBlock, $request->roomStatus())
            ? ['type' => 'success', 'message' => __(':unit is back in service.', ['unit' => $unit])]
            : ['type' => 'error', 'message' => __('Block for :unit cannot be ended. Only a block that is running today can end.', ['unit' => $unit])]);

        return back();
    }

    public function destroy(Request $request, Property $property, RoomBlock $roomBlock, CancelRoomBlock $cancelRoomBlock): RedirectResponse
    {
        $unit = $this->unitName($roomBlock->room, $roomBlock->bed);

        Inertia::flash('toast', $cancelRoomBlock($request->user(), $roomBlock)
            ? ['type' => 'success', 'message' => __('Block for :unit cancelled.', ['unit' => $unit])]
            : ['type' => 'error', 'message' => __('Block for :unit cannot be cancelled because it has started. End it instead.', ['unit' => $unit])]);

        return back();
    }

    /**
     * End every ticked block that is running today.
     */
    public function bulkEnd(BulkEndRoomBlocksRequest $request, Property $property, EndRoomBlocks $endRoomBlocks): RedirectResponse
    {
        $result = $endRoomBlocks($request->user(), $request->blocks(), $request->roomStatus());

        Inertia::flash('toast', $this->bulkToast(
            $result,
            trans_choice('{0} No blocks ended.|{1} :count block ended.|[2,*] :count blocks ended.', $result->done),
            fn (array $skip): string => __('Block for :unit is not running today.', ['unit' => $this->unitName($skip['room'], $skip['bed'])]),
        ));

        return back();
    }

    /**
     * Cancel every ticked block that has not started.
     */
    public function bulkCancel(BulkRoomBlocksRequest $request, Property $property, CancelRoomBlocks $cancelRoomBlocks): RedirectResponse
    {
        $result = $cancelRoomBlocks($request->user(), $request->blocks());

        Inertia::flash('toast', $this->bulkToast(
            $result,
            trans_choice('{0} No blocks cancelled.|{1} :count block cancelled.|[2,*] :count blocks cancelled.', $result->done),
            fn (array $skip): string => __('Block for :unit has started. End it instead.', ['unit' => $this->unitName($skip['room'], $skip['bed'])]),
        ));

        return back();
    }

    /**
     * The toast of a bulk Action: what it did, then up to three skipped units with the reason.
     *
     * @param  callable(array{room: Room, bed: Bed|null, refused: RoomBlockRefused|null}): string  $reason
     * @return array{type: string, message: string}
     */
    private function bulkToast(RoomBlockBulkResult $result, string $message, callable $reason): array
    {
        if ($result->skipped !== []) {
            $reasons = array_map($reason, array_slice($result->skipped, 0, 3));
            $more = count($result->skipped) - count($reasons);

            $message .= ' '.__(':count skipped: :reasons', [
                'count' => count($result->skipped),
                'reasons' => implode(' ', $reasons).($more > 0 ? ' '.__('and :count more.', ['count' => $more]) : ''),
            ]);
        }

        return [
            'type' => $result->done === 0 ? 'error' : ($result->skipped === [] ? 'success' : 'info'),
            'message' => $message,
        ];
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
