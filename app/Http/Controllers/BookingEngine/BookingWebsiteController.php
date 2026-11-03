<?php

namespace App\Http\Controllers\BookingEngine;

use App\Actions\BookingEngine\PublishBookingSite;
use App\Actions\BookingEngine\SaveBookingWebsiteGeneral;
use App\Actions\BookingEngine\SetUpBookingSite;
use App\Actions\BookingEngine\UnpublishBookingSite;
use App\Data\BookingEngine\BookingWebsiteGeneralData;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingEngine\SaveBookingWebsiteGeneralRequest;
use App\Models\Property;
use App\Services\BookingEngine\BookingSiteChecklist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function updateGeneral(SaveBookingWebsiteGeneralRequest $request, Property $property, SaveBookingWebsiteGeneral $saveBookingWebsiteGeneral): RedirectResponse
    {
        $saveBookingWebsiteGeneral($property, $request->user(), $request->string('slug')->toString(), $request->filled('google_maps_url') ? $request->string('google_maps_url')->toString() : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Booking website saved.')]);

        return to_route('settings.booking-website.general', $property);
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
