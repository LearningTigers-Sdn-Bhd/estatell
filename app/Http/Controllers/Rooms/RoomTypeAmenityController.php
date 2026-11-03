<?php

namespace App\Http\Controllers\Rooms;

use App\Actions\Rooms\SyncRoomTypeAmenities;
use App\Data\BookingEngine\AmenityPickerData;
use App\Data\Rooms\RoomTypeData;
use App\Enums\AmenityType;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rooms\SaveRoomTypeAmenitiesRequest;
use App\Models\Property;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Amenities tab of the room type page. See docs/28-booking-engine/06-content-registries.md#amenities.
 */
class RoomTypeAmenityController extends Controller
{
    public function edit(Request $request, Property $property, RoomType $roomType): Response
    {
        return Inertia::render('settings/room-types/amenities', [
            'roomType' => RoomTypeData::fromModel($roomType),
            'roomCount' => $roomType->rooms()->active()->count(),
            'canManage' => $request->user()->can(PermissionEnum::RoomsManage->value),
            'amenities' => AmenityPickerData::forType(AmenityType::Room, $roomType->amenities()->get()),
        ]);
    }

    public function update(SaveRoomTypeAmenitiesRequest $request, Property $property, RoomType $roomType, SyncRoomTypeAmenities $syncAmenities): RedirectResponse
    {
        Inertia::flash('toast', $syncAmenities($request->user(), $roomType, $request->amenityIds())
            ? ['type' => 'success', 'message' => __('Amenities saved.')]
            : ['type' => 'error', 'message' => __('The amenity list changed, or the room type is archived. Reload the page and try again.')]);

        return back();
    }
}
