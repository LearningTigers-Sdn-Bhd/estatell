<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SaveOnboardingRoomTypes;
use App\Data\Onboarding\OnboardingRoomTypeData;
use App\Enums\OnboardingStep;
use App\Enums\SellUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SaveRoomTypesRequest;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\Onboarding\OnboardingProgress;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoomTypeController extends Controller
{
    /**
     * The active room types in display order, one row each, sold by room or by bed. A locked step opens as a preview.
     */
    public function edit(Property $property, OnboardingProgress $progress): Response
    {
        $blockingStep = $progress->blockingStep($property, OnboardingStep::RoomTypes);

        return Inertia::render('onboarding/room-types', [
            'roomTypes' => $property->roomTypes()->active()->ordered()->withExists('rooms')->get()
                ->map(fn (RoomType $roomType): OnboardingRoomTypeData => OnboardingRoomTypeData::fromModel($roomType)),
            'sellUnits' => SellUnit::options(),
            'maxRows' => SaveRoomTypesRequest::MAX_ROWS,
            'locked' => $blockingStep !== null,
            'blockedBy' => $blockingStep === null ? null : [
                'label' => $blockingStep->label(),
                'url' => route($blockingStep->routeName(), ['property' => $property->code], absolute: false),
            ],
        ]);
    }

    public function update(SaveRoomTypesRequest $request, Property $property, SaveOnboardingRoomTypes $saveRoomTypes): RedirectResponse
    {
        $saveRoomTypes($property, $request->user(), $request->rows());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room types saved.')]);

        $next = OnboardingStep::RoomTypes->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }
}
