<?php

namespace App\Http\Controllers\Rooms;

use App\Actions\Rooms\SetRoomTypeAmenity;
use App\Data\BookingEngine\AmenityPickerData;
use App\Data\Rooms\RoomTypeData;
use App\Data\Rooms\RoomTypeSwitchData;
use App\Enums\AmenityType;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingEngine\SetAmenityRequest;
use App\Models\Property;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Amenities tab of the room type page. See docs/29-booking-engine/06-content-registries.md#amenities.
 */
class RoomTypeAmenityController extends Controller
{
    public function edit(Request $request, Property $property, RoomType $roomType): Response
    {
        return Inertia::render('settings/room-types/amenities', [
            'roomType' => RoomTypeData::fromModel($roomType),
            'roomTypeSwitch' => RoomTypeSwitchData::forProperty($property, $roomType),
            'roomCount' => $roomType->rooms()->active()->count(),
            'canManage' => $request->user()->can(PermissionEnum::RoomsManage->value),
            'amenities' => AmenityPickerData::forType(AmenityType::Room, $roomType->amenities()->get()),
            'page' => max(1, $request->integer('page', 1)),
            'listUrl' => $request->url(),
        ]);
    }

    public function update(SetAmenityRequest $request, Property $property, RoomType $roomType, SetRoomTypeAmenity $setRoomTypeAmenity): RedirectResponse
    {
        if (! $setRoomTypeAmenity($request->user(), $roomType, $request->integer('amenity_id'), $request->boolean('active'))) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The amenity list changed, or the room type is archived. Reload the page and try again.')]);
        }

        return back();
    }
}
