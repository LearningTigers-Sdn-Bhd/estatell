<?php

namespace App\Http\Controllers\BookingEngine;

use App\Actions\BookingEngine\PublishBookingSite;
use App\Actions\BookingEngine\SaveBookingWebsiteGeneral;
use App\Actions\BookingEngine\SetPropertyAmenity;
use App\Actions\BookingEngine\SetUpBookingSite;
use App\Actions\BookingEngine\UnpublishBookingSite;
use App\Data\BookingEngine\AmenityPickerData;
use App\Data\BookingEngine\BookingWebsiteGeneralData;
use App\Enums\AmenityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingEngine\SaveBookingWebsiteGeneralRequest;
use App\Http\Requests\BookingEngine\SetAmenityRequest;
use App\Models\Property;
use App\Services\BookingEngine\BookingSiteChecklist;
use App\Services\BookingEngine\GoogleMapsLink;
use App\Services\BookingEngine\InvalidMapsLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BookingWebsiteController extends Controller
{
    /**
     * The General tab: status, web address, Google Maps link and the publish checklist.
     */
    public function general(Property $property, SetUpBookingSite $setUpBookingSite, BookingSiteChecklist $checklist): Response
    {
        $site = $setUpBookingSite($property);

        return Inertia::render('settings/booking-website/general', [
            'general' => BookingWebsiteGeneralData::fromModels($property->refresh(), $site, $checklist),
        ]);
    }

    public function updateGeneral(SaveBookingWebsiteGeneralRequest $request, Property $property, SaveBookingWebsiteGeneral $saveBookingWebsiteGeneral, GoogleMapsLink $googleMapsLink): RedirectResponse
    {
        $googleMapsUrl = $request->filled('google_maps_url') ? $request->string('google_maps_url')->toString() : null;
        $place = null;

        if ($googleMapsUrl !== null && ($googleMapsUrl !== $property->google_maps_url || $property->latitude === null)) {
            try {
                $place = $googleMapsLink->parse($googleMapsUrl);
            } catch (InvalidMapsLink $exception) {
                throw ValidationException::withMessages(['google_maps_url' => $exception->getMessage()]);
            }
        }

        $saveBookingWebsiteGeneral($property, $request->user(), $request->string('slug')->toString(), $googleMapsUrl, $place);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Booking website saved.')]);

        return to_route('settings.booking-website.general', $property);
    }

    /**
     * The Amenities tab: the property amenities. The List view's page lives in the URL (`?page=2`) and the browser cuts it from the loaded list.
     */
    public function amenities(Request $request, Property $property): Response
    {
        return Inertia::render('settings/booking-website/amenities', [
            'amenities' => AmenityPickerData::forType(AmenityType::Property, $property->amenities()->get()),
            'page' => max(1, $request->integer('page', 1)),
            'listUrl' => $request->url(),
        ]);
    }

    public function updateAmenities(SetAmenityRequest $request, Property $property, SetPropertyAmenity $setPropertyAmenity): RedirectResponse
    {
        if (! $setPropertyAmenity($request->user(), $property, $request->integer('amenity_id'), $request->boolean('active'))) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The amenity list changed. Reload the page and try again.')]);
        }

        return back();
    }

    public function publish(Request $request, Property $property, SetUpBookingSite $setUpBookingSite, PublishBookingSite $publishBookingSite): RedirectResponse
    {
        Inertia::flash('toast', $publishBookingSite($setUpBookingSite($property), $request->user())
            ? ['type' => 'success', 'message' => __('Booking website published.')]
            : ['type' => 'error', 'message' => __('The site is already published, or the checklist is not done.')]);

        return to_route('settings.booking-website.general', $property);
    }

    public function unpublish(Request $request, Property $property, SetUpBookingSite $setUpBookingSite, UnpublishBookingSite $unpublishBookingSite): RedirectResponse
    {
        Inertia::flash('toast', $unpublishBookingSite($setUpBookingSite($property), $request->user())
            ? ['type' => 'success', 'message' => __('Booking website unpublished.')]
            : ['type' => 'error', 'message' => __('The site is not published.')]);

        return to_route('settings.booking-website.general', $property);
    }
}
