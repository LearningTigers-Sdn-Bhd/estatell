<?php

namespace App\Http\Controllers\Rooms;

use App\Actions\Rooms\ArchiveRoomType;
use App\Actions\Rooms\AssignRoomTypeRooms;
use App\Actions\Rooms\CreateRoomType;
use App\Actions\Rooms\ReorderRoomTypes;
use App\Actions\Rooms\RestoreRoomType;
use App\Actions\Rooms\UpdateRoomType;
use App\Data\Rooms\RoomData;
use App\Data\Rooms\RoomGroupData;
use App\Data\Rooms\RoomTypeData;
use App\Data\Rooms\RoomTypeRoomChoiceData;
use App\Enums\PermissionEnum;
use App\Enums\SellUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rooms\AssignRoomTypeRoomsRequest;
use App\Http\Requests\Rooms\ReorderRoomTypesRequest;
use App\Http\Requests\Rooms\RoomTypeRequest;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\RoomType;
use App\Services\Channels\ChannelArchiveGuard;
use App\Services\Rooms\RoomNumberGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoomTypeController extends Controller
{
    /**
     * Active room types in display order, then archived ones. The list is bounded, so it loads once.
     */
    public function index(Property $property): Response
    {
        return Inertia::render('settings/room-types/index', [
            'roomTypes' => $property->roomTypes()
                ->orderByRaw('archived_at IS NOT NULL')
                ->ordered()
                ->get()
                ->map(fn (RoomType $roomType): RoomTypeData => RoomTypeData::fromModel($roomType)),
            'sellUnits' => SellUnit::options(),
            'termsDocuments' => $this->termsDocumentOptions($property),
        ]);
    }

    public function store(RoomTypeRequest $request, Property $property, CreateRoomType $createRoomType): RedirectResponse
    {
        $roomType = $createRoomType($property, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created.', ['name' => $roomType->name])]);

        return to_route('settings.room-types.edit', [$property, $roomType]);
    }

    /**
     * The Details tab of the room type page. Each tab is its own URL and loads only its own data.
     */
    public function edit(Request $request, Property $property, RoomType $roomType): Response
    {
        return Inertia::render('settings/room-types/edit', [
            'roomType' => RoomTypeData::fromModel($roomType),
            'roomCount' => $this->roomCount($roomType),
            'canManage' => $request->user()->can(PermissionEnum::RoomsManage->value),
            'sellUnits' => SellUnit::options(),
            'termsDocuments' => $this->termsDocumentOptions($property),
        ]);
    }

    /**
     * The Rooms tab: the active rooms of this type, the groups to put them in and the rooms of other types that can move in.
     */
    public function editRooms(Request $request, Property $property, RoomType $roomType): Response
    {
        return Inertia::render('settings/room-types/rooms', [
            'roomType' => RoomTypeData::fromModel($roomType),
            'roomCount' => $this->roomCount($roomType),
            'canManage' => $request->user()->can(PermissionEnum::RoomsManage->value),
            'rooms' => $roomType->rooms()->active()->ordered()->get()
                ->map(fn (Room $room): RoomData => RoomData::fromModel($room)),
            'otherRooms' => $property->rooms()->active()
                ->where('room_type_id', '!=', $roomType->id)
                ->with('roomType')
                ->ordered()
                ->get()
                ->map(fn (Room $room): RoomTypeRoomChoiceData => RoomTypeRoomChoiceData::fromModel($room)),
            'roomGroups' => $property->roomGroups()->ordered()->get()
                ->map(fn (RoomGroup $group): RoomGroupData => RoomGroupData::fromModel($group)),
            'generateLimit' => RoomNumberGenerator::MAX_ROOMS,
        ]);
    }

    public function updateRooms(AssignRoomTypeRoomsRequest $request, Property $property, RoomType $roomType, AssignRoomTypeRooms $assignRooms): RedirectResponse
    {
        $refusal = $assignRooms($request->user(), $roomType, $request->rows());

        Inertia::flash('toast', $refusal === null
            ? ['type' => 'success', 'message' => __('Rooms saved.')]
            : ['type' => 'error', 'message' => $refusal]);

        return to_route('settings.room-types.rooms.edit', [$property, $roomType]);
    }

    public function update(RoomTypeRequest $request, Property $property, RoomType $roomType, UpdateRoomType $updateRoomType): RedirectResponse
    {
        Inertia::flash('toast', $updateRoomType($request->user(), $roomType, $request->validated())
            ? ['type' => 'success', 'message' => __(':name saved.', ['name' => $roomType->name])]
            : ['type' => 'error', 'message' => __(':name is archived. Restore it before editing.', ['name' => $roomType->name])]);

        return to_route('settings.room-types.edit', [$property, $roomType]);
    }

    public function reorder(ReorderRoomTypesRequest $request, Property $property, ReorderRoomTypes $reorderRoomTypes): RedirectResponse
    {
        if (! $reorderRoomTypes($property, $request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The room types changed. Reload the page and try again.')]);
        }

        return to_route('settings.room-types.index', $property);
    }

    public function archive(Request $request, Property $property, RoomType $roomType, ArchiveRoomType $archiveRoomType, ChannelArchiveGuard $channelGuard): RedirectResponse
    {
        Inertia::flash('toast', $archiveRoomType($request->user(), $roomType)
            ? ['type' => 'success', 'message' => __(':name archived.', ['name' => $roomType->name])]
            : ['type' => 'error', 'message' => $channelGuard->roomTypeMessage($roomType) ?? __(':name is already archived.', ['name' => $roomType->name])]);

        return back(fallback: route('settings.room-types.index', $property));
    }

    public function restore(Request $request, Property $property, RoomType $roomType, RestoreRoomType $restoreRoomType): RedirectResponse
    {
        Inertia::flash('toast', $restoreRoomType($request->user(), $roomType)
            ? ['type' => 'success', 'message' => __(':name restored.', ['name' => $roomType->name])]
            : ['type' => 'error', 'message' => __(':name cannot be restored. Another room type uses the same name.', ['name' => $roomType->name])]);

        return back(fallback: route('settings.room-types.index', $property));
    }

    /**
     * The number in the "Rooms (N)" tab label: active rooms of the room type.
     */
    private function roomCount(RoomType $roomType): int
    {
        return $roomType->rooms()->active()->count();
    }

    /**
     * The terms documents to pick from, or none while the property has only its default one.
     *
     * @return list<array{value: int, label: string}>
     */
    private function termsDocumentOptions(Property $property): array
    {
        $documents = $property->termsDocuments()->orderByDesc('is_default')->orderBy('name')->get();

        if ($documents->count() < 2) {
            return [];
        }

        return array_values($documents->map(fn ($document): array => ['value' => $document->id, 'label' => $document->name])->all());
    }
}
