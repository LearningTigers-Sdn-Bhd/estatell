<?php

namespace App\Http\Controllers\Rooms;

use App\Actions\Rooms\ArchiveRoom;
use App\Actions\Rooms\ArchiveRooms;
use App\Actions\Rooms\ChangeRoomsType;
use App\Actions\Rooms\CreateRoom;
use App\Actions\Rooms\GenerateRooms;
use App\Actions\Rooms\RestoreRoom;
use App\Actions\Rooms\UpdateRoom;
use App\Data\Rooms\BedData;
use App\Data\Rooms\GeneratedRoomData;
use App\Data\Rooms\RoomBulkResultData;
use App\Data\Rooms\RoomData;
use App\Data\Rooms\RoomGroupData;
use App\Data\Rooms\RoomTypeData;
use App\Enums\PermissionEnum;
use App\Enums\SellUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rooms\BulkRoomsRequest;
use App\Http\Requests\Rooms\ChangeRoomsTypeRequest;
use App\Http\Requests\Rooms\GenerateRoomsRequest;
use App\Http\Requests\Rooms\RoomRequest;
use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\RoomType;
use App\Services\Rooms\BedLabelGenerator;
use App\Services\Rooms\RoomNumberAvailability;
use App\Services\Rooms\RoomNumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    /**
     * Groups, room types, beds and every room of the property. The lists are bounded, so they load once
     * and the browser groups and filters them.
     */
    public function index(Request $request, Property $property, BedLabelGenerator $bedLabels): Response
    {
        return Inertia::render('settings/rooms/index', [
            'rooms' => $property->rooms()->ordered()->get()
                ->map(fn (Room $room): RoomData => RoomData::fromModel($room)),
            'beds' => $property->beds()->ordered()->get()
                ->map(fn (Bed $bed): BedData => BedData::fromModel($bed)),
            'bedPrefixes' => $this->bedPrefixes($property, $bedLabels),
            'roomGroups' => $property->roomGroups()->ordered()->get()
                ->map(fn (RoomGroup $group): RoomGroupData => RoomGroupData::fromModel($group)),
            'roomTypes' => $property->roomTypes()->ordered()->get()
                ->map(fn (RoomType $roomType): RoomTypeData => RoomTypeData::fromModel($roomType)),
            'generateLimit' => RoomNumberGenerator::MAX_ROOMS,
            'bedLimit' => BedLabelGenerator::MAX_BEDS,
            'canManage' => $request->user()->can(PermissionEnum::RoomsManage->value),
        ]);
    }

    /**
     * The default bed prefix (the room's letter) of each active Bed room, keyed by room id.
     *
     * @return array<int, string>
     */
    private function bedPrefixes(Property $property, BedLabelGenerator $bedLabels): array
    {
        return $property->rooms()->active()
            ->whereHas('roomType', fn ($query) => $query->where('sell_unit', SellUnit::Bed->value))
            ->ordered()
            ->pluck('id')
            ->values()
            ->mapWithKeys(fn (int $id, int $index): array => [$id => $bedLabels->letter($index)])
            ->all();
    }

    public function store(RoomRequest $request, Property $property, CreateRoom $createRoom): RedirectResponse
    {
        $room = $createRoom($property, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room :number created.', ['number' => $room->number])]);

        return to_route('settings.rooms.index', $property);
    }

    public function update(RoomRequest $request, Property $property, Room $room, UpdateRoom $updateRoom): RedirectResponse
    {
        Inertia::flash('toast', $updateRoom($request->user(), $room, $request->validated())
            ? ['type' => 'success', 'message' => __('Room :number saved.', ['number' => $request->validated('number')])]
            : ['type' => 'error', 'message' => __('Room :number is archived. Restore it before editing.', ['number' => $room->number])]);

        return to_route('settings.rooms.index', $property);
    }

    public function archive(Request $request, Property $property, Room $room, ArchiveRoom $archiveRoom): RedirectResponse
    {
        Inertia::flash('toast', $archiveRoom($request->user(), $room)
            ? ['type' => 'success', 'message' => __('Room :number archived.', ['number' => $room->number])]
            : ['type' => 'error', 'message' => __('Room :number is already archived.', ['number' => $room->number])]);

        return to_route('settings.rooms.index', $property);
    }

    /**
     * Bulk Change type: the rooms that pass the guards change, the toast lists the refused ones.
     */
    public function changeType(ChangeRoomsTypeRequest $request, Property $property, ChangeRoomsType $changeRoomsType): RedirectResponse
    {
        $result = $changeRoomsType($request->user(), $request->rooms(), $request->roomType());

        $this->flashBulkResult($result, trans_choice('{0} No rooms changed.|{1} :count room changed.|[2,*] :count rooms changed.', $result->done));

        return to_route('settings.rooms.index', $property);
    }

    public function archiveMany(BulkRoomsRequest $request, Property $property, ArchiveRooms $archiveRooms): RedirectResponse
    {
        $result = $archiveRooms($request->user(), $request->rooms());

        $this->flashBulkResult($result, trans_choice('{0} No rooms archived.|{1} :count room archived.|[2,*] :count rooms archived.', $result->done));

        return to_route('settings.rooms.index', $property);
    }

    public function restore(Request $request, Property $property, Room $room, RestoreRoom $restoreRoom): RedirectResponse
    {
        Inertia::flash('toast', $restoreRoom($request->user(), $room)
            ? ['type' => 'success', 'message' => __('Room :number restored.', ['number' => $room->number])]
            : ['type' => 'error', 'message' => __('Room :number cannot be restored. Another room uses the same number.', ['number' => $room->number])]);

        return to_route('settings.rooms.index', $property);
    }

    /**
     * The numbers a generator run would create, with the ones already taken marked.
     */
    public function preview(GenerateRoomsRequest $request, Property $property, RoomNumberGenerator $generator, RoomNumberAvailability $availability): JsonResponse
    {
        $plan = $generator->generate($request->mode(), $request->generatorInput());
        $taken = array_map('mb_strtolower', $availability->taken($property, array_column($plan, 'number')));

        return response()->json([
            'rooms' => array_map(
                fn (array $entry): GeneratedRoomData => GeneratedRoomData::fromPlanEntry($entry, in_array(mb_strtolower($entry['number']), $taken, true)),
                $plan,
            ),
        ]);
    }

    public function generate(GenerateRoomsRequest $request, Property $property, RoomNumberGenerator $generator, GenerateRooms $generateRooms): RedirectResponse
    {
        $result = $generateRooms(
            $property,
            $request->user(),
            $property->roomTypes()->findOrFail($request->integer('room_type_id')),
            $request->validated('room_group_id') ? $property->roomGroups()->findOrFail($request->integer('room_group_id')) : null,
            $generator->generate($request->mode(), $request->generatorInput()),
        );

        if ($result['conflicts'] !== []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No rooms created. These numbers are already used: :numbers.', ['numbers' => implode(', ', array_slice($result['conflicts'], 0, 10))])]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} :count room created.|[2,*] :count rooms created.', $result['created'], ['count' => $result['created']])]);

        return back();
    }

    /**
     * The count, then at most 3 refusals and "and N more.". Error when nothing changed because of refusals.
     */
    private function flashBulkResult(RoomBulkResultData $result, string $message): void
    {
        if ($result->refused !== []) {
            $listed = array_slice($result->refused, 0, 3);
            $more = count($result->refused) - count($listed);

            $message .= ' '.implode(' ', $listed).($more > 0 ? ' '.__('and :count more.', ['count' => $more]) : '');
        }

        Inertia::flash('toast', [
            'type' => $result->refused === [] ? ($result->done === 0 ? 'info' : 'success') : ($result->done === 0 ? 'error' : 'info'),
            'message' => $message,
        ]);
    }
}
