<?php

namespace App\Http\Controllers\Rooms;

use App\Actions\Rooms\ArchiveBed;
use App\Actions\Rooms\CreateBed;
use App\Actions\Rooms\GenerateBeds;
use App\Actions\Rooms\RestoreBed;
use App\Actions\Rooms\UpdateBed;
use App\Enums\SellUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rooms\BedRequest;
use App\Http\Requests\Rooms\GenerateBedsRequest;
use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BedController extends Controller
{
    public function store(BedRequest $request, Property $property, Room $room, CreateBed $createBed): RedirectResponse
    {
        $bed = $createBed($request->user(), $room, $request->validated());

        Inertia::flash('toast', $bed
            ? ['type' => 'success', 'message' => __('Bed :label created.', ['label' => $bed->label])]
            : ['type' => 'error', 'message' => __('Room :number cannot have beds. It is archived or its room type sells rooms.', ['number' => $room->number])]);

        return to_route('settings.rooms.index', $property);
    }

    public function update(BedRequest $request, Property $property, Room $room, Bed $bed, UpdateBed $updateBed): RedirectResponse
    {
        Inertia::flash('toast', $updateBed($request->user(), $bed, $request->validated())
            ? ['type' => 'success', 'message' => __('Bed :label saved.', ['label' => $request->validated('label')])]
            : ['type' => 'error', 'message' => __('Bed :label is archived. Restore it before editing.', ['label' => $bed->label])]);

        return to_route('settings.rooms.index', $property);
    }

    public function archive(Request $request, Property $property, Room $room, Bed $bed, ArchiveBed $archiveBed): RedirectResponse
    {
        Inertia::flash('toast', $archiveBed($request->user(), $bed)
            ? ['type' => 'success', 'message' => __('Bed :label archived.', ['label' => $bed->label])]
            : ['type' => 'error', 'message' => __('Bed :label is already archived.', ['label' => $bed->label])]);

        return to_route('settings.rooms.index', $property);
    }

    public function restore(Request $request, Property $property, Room $room, Bed $bed, RestoreBed $restoreBed): RedirectResponse
    {
        Inertia::flash('toast', $restoreBed($request->user(), $bed)
            ? ['type' => 'success', 'message' => __('Bed :label restored.', ['label' => $bed->label])]
            : ['type' => 'error', 'message' => __('Bed :label cannot be restored. Another bed in the room uses the same label.', ['label' => $bed->label])]);

        return to_route('settings.rooms.index', $property);
    }

    /**
     * Add beds to the picked Bed rooms. Without a pick, every active Bed room that has no active bed.
     */
    public function generate(GenerateBedsRequest $request, Property $property, GenerateBeds $generateBeds): RedirectResponse
    {
        $roomIds = $request->validated('room_ids')
            ?? $property->rooms()->active()
                ->whereHas('roomType', fn ($query) => $query->where('sell_unit', SellUnit::Bed->value))
                ->whereDoesntHave('beds', fn ($query) => $query->active())
                ->pluck('id')
                ->all();

        $created = $generateBeds($property, $request->user(), $roomIds, (int) $request->validated('count'), $request->validated('prefix'));

        Inertia::flash('toast', $created === 0
            ? ['type' => 'error', 'message' => __('No beds added. Every dorm room already has beds.')]
            : ['type' => 'success', 'message' => trans_choice('{1} :count bed added.|[2,*] :count beds added.', $created, ['count' => $created])]);

        return to_route('settings.rooms.index', $property);
    }
}
