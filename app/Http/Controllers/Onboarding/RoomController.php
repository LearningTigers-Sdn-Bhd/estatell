<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SaveOnboardingRooms;
use App\Actions\Rooms\ArchiveRoom;
use App\Data\Rooms\RoomData;
use App\Data\Rooms\RoomGroupData;
use App\Data\Rooms\RoomTypeData;
use App\Enums\OnboardingStep;
use App\Enums\SellUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SaveRoomsRequest;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\RoomType;
use App\Services\Onboarding\OnboardingProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    /**
     * The room types sold by room, the saved groups and the saved rooms. The browser shows the saved rooms as chips and
     * derives the count and preview of the range rows. A locked step opens as a preview.
     */
    public function edit(Property $property, OnboardingProgress $progress): Response
    {
        $blockingStep = $progress->blockingStep($property, OnboardingStep::Rooms);

        return Inertia::render('onboarding/rooms', [
            'roomTypes' => $property->roomTypes()->active()->ordered()->where('sell_unit', SellUnit::Room)->get()
                ->map(fn (RoomType $roomType): RoomTypeData => RoomTypeData::fromModel($roomType)),
            'groups' => $property->roomGroups()->orderBy('position')->orderBy('id')->get()
                ->map(fn (RoomGroup $group): RoomGroupData => RoomGroupData::fromModel($group)),
            'rooms' => $property->rooms()->active()->ordered()->get()
                ->map(fn (Room $room): RoomData => RoomData::fromModel($room)),
            'maxRanges' => SaveRoomsRequest::MAX_RANGES,
            'maxGroups' => SaveRoomsRequest::MAX_GROUPS,
            'locked' => $blockingStep !== null,
            'blockedBy' => $blockingStep === null ? null : [
                'label' => $blockingStep->label(),
                'url' => $blockingStep->routeName() === null ? null : route($blockingStep->routeName(), ['property' => $property->code], absolute: false),
            ],
        ]);
    }

    public function update(SaveRoomsRequest $request, Property $property, SaveOnboardingRooms $saveRooms): RedirectResponse
    {
        $result = $saveRooms($property, $request->user(), $request->groups(), $request->ranges());

        if ($result['conflicts'] !== []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Some room numbers are already in use. Nothing was saved.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rooms saved.')]);

        $next = OnboardingStep::Rooms->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }

    public function destroy(Request $request, Property $property, int $room, OnboardingProgress $progress, ArchiveRoom $archiveRoom): RedirectResponse
    {
        $model = $property->rooms()->active()->findOrFail($room);

        if ($progress->blockingStep($property, OnboardingStep::Rooms) !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Finish the earlier section first.')]);

            return back();
        }

        $archiveRoom($request->user(), $model);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room :number removed.', ['number' => $model->number])]);

        return back();
    }
}
