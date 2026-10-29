<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SaveOnboardingRooms;
use App\Data\Onboarding\OnboardingRoomData;
use App\Data\Onboarding\OnboardingRoomGroupRowData;
use App\Data\Onboarding\OnboardingRoomTypeTileData;
use App\Enums\OnboardingStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\PreviewRoomsRequest;
use App\Http\Requests\Onboarding\SaveRoomsRequest;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Rooms\RoomNumberRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    /**
     * The active room types as picker tiles, the group rows with their saved numbers as text and parsed, and each saved
     * room's type and bed count for the grid. A locked step opens as a preview.
     */
    public function edit(Property $property, OnboardingProgress $progress, RoomNumberRows $rows): Response
    {
        $blockingStep = $progress->blockingStep($property, OnboardingStep::Rooms);
        $groups = OnboardingRoomGroupRowData::forProperty($property);

        return Inertia::render('onboarding/rooms', [
            'roomTypes' => $property->roomTypes()->active()->ordered()->get()
                ->map(fn (RoomType $roomType): OnboardingRoomTypeTileData => OnboardingRoomTypeTileData::fromModel($roomType)),
            'groups' => $groups,
            'parsed' => $rows->parse(array_map(fn (OnboardingRoomGroupRowData $group): string => $group->numbers, $groups)),
            'rooms' => $property->rooms()->active()->ordered()->withCount(['beds' => fn ($query) => $query->active()])->get()
                ->map(fn (Room $room): OnboardingRoomData => OnboardingRoomData::fromModel($room)),
            'hasSavedGroups' => $property->roomGroups()->exists(),
            'maxGroups' => SaveRoomsRequest::MAX_GROUPS,
            'maxBeds' => SaveRoomsRequest::MAX_BEDS,
            'locked' => $blockingStep !== null,
            'blockedBy' => $blockingStep === null ? null : [
                'label' => $blockingStep->label(),
                'url' => route($blockingStep->routeName(), ['property' => $property->code], absolute: false),
            ],
        ]);
    }

    /**
     * Parse the numbers fields as they are on screen. The browser never parses room numbers.
     */
    public function preview(PreviewRoomsRequest $request, Property $property, RoomNumberRows $rows): JsonResponse
    {
        return response()->json(['rows' => $rows->parse($request->lists())]);
    }

    public function update(SaveRoomsRequest $request, Property $property, SaveOnboardingRooms $saveRooms): RedirectResponse
    {
        $taken = $saveRooms($property, $request->user(), $request->usesGroups(), $request->groups(), $request->typeByNumber(), $request->bedsPerRoom());

        if ($taken !== []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Some room numbers are already in use. Nothing was saved.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rooms saved.')]);

        $next = OnboardingStep::Rooms->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }
}
