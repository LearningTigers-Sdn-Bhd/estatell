<?php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Controllers\Controller;
use App\Http\Resources\Booking\V1\BookingListedPropertyResource;
use App\Http\Resources\Booking\V1\BookingPropertyResource;
use App\Http\Resources\Booking\V1\BookingRoomTypeResource;
use App\Http\Resources\Booking\V1\BookingSiteResource;
use App\Services\BookingEngine\BookingClientReach;
use App\Services\BookingEngine\BookingContent;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    /**
     * @return array<int, mixed>
     */
    public function properties(Request $request, BookingClientReach $reach): array
    {
        return BookingListedPropertyResource::collection($reach->properties($request->attributes->get('booking_client')))->resolve($request);
    }

    public function site(Request $request, BookingContent $content): BookingSiteResource
    {
        return new BookingSiteResource($content->site($request->attributes->get('booking_property')));
    }

    public function property(Request $request, BookingContent $content): BookingPropertyResource
    {
        return new BookingPropertyResource($content->property($request->attributes->get('booking_property')));
    }

    /**
     * @return array<int, mixed>
     */
    public function roomTypes(Request $request, BookingContent $content): array
    {
        return BookingRoomTypeResource::collection($content->roomTypes($request->attributes->get('booking_property')))->resolve($request);
    }
}
