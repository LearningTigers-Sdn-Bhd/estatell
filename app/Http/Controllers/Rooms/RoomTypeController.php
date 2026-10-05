<?php

namespace App\Http\Controllers\Rooms;

use App\Actions\Rooms\ArchiveRoomType;
use App\Actions\Rooms\CreateRoomType;
use App\Actions\Rooms\ReorderRoomTypes;
use App\Actions\Rooms\RestoreRoomType;
use App\Actions\Rooms\UpdateRoomType;
use App\Data\Rooms\RoomTypeData;
use App\Enums\SellUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rooms\ReorderRoomTypesRequest;
use App\Http\Requests\Rooms\RoomTypeRequest;
use App\Models\Property;
use App\Models\RoomType;
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

        return to_route('settings.room-types.index', $property);
    }

    public function update(RoomTypeRequest $request, Property $property, RoomType $roomType, UpdateRoomType $updateRoomType): RedirectResponse
    {
        Inertia::flash('toast', $updateRoomType($request->user(), $roomType, $request->validated())
            ? ['type' => 'success', 'message' => __(':name saved.', ['name' => $roomType->name])]
            : ['type' => 'error', 'message' => __(':name is archived. Restore it before editing.', ['name' => $roomType->name])]);

        return to_route('settings.room-types.index', $property);
    }

    public function reorder(ReorderRoomTypesRequest $request, Property $property, ReorderRoomTypes $reorderRoomTypes): RedirectResponse
    {
        if (! $reorderRoomTypes($property, $request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The room types changed. Reload the page and try again.')]);
        }

        return to_route('settings.room-types.index', $property);
    }

    public function archive(Request $request, Property $property, RoomType $roomType, ArchiveRoomType $archiveRoomType): RedirectResponse
    {
        Inertia::flash('toast', $archiveRoomType($request->user(), $roomType)
            ? ['type' => 'success', 'message' => __(':name archived.', ['name' => $roomType->name])]
            : ['type' => 'error', 'message' => __(':name is already archived.', ['name' => $roomType->name])]);

        return to_route('settings.room-types.index', $property);
    }

    public function restore(Request $request, Property $property, RoomType $roomType, RestoreRoomType $restoreRoomType): RedirectResponse
    {
        Inertia::flash('toast', $restoreRoomType($request->user(), $roomType)
            ? ['type' => 'success', 'message' => __(':name restored.', ['name' => $roomType->name])]
            : ['type' => 'error', 'message' => __(':name cannot be restored. Another room type uses the same name.', ['name' => $roomType->name])]);

        return to_route('settings.room-types.index', $property);
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

        return $documents->map(fn ($document): array => ['value' => $document->id, 'label' => $document->name])->all();
    }
}
