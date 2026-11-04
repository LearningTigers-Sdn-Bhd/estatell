<?php

namespace App\Http\Controllers\BookingEngine;

use App\Actions\BookingEngine\PublishBookingSite;
use App\Actions\BookingEngine\RemoveSiteImage;
use App\Actions\BookingEngine\SaveBookingWebsiteDesign;
use App\Actions\BookingEngine\SaveBookingWebsiteGeneral;
use App\Actions\BookingEngine\SaveBookingWebsiteSeo;
use App\Actions\BookingEngine\SetPropertyAmenity;
use App\Actions\BookingEngine\SetUpBookingSite;
use App\Actions\BookingEngine\UnpublishBookingSite;
use App\Actions\BookingEngine\UploadSiteImage;
use App\Data\BookingEngine\AmenityPickerData;
use App\Data\BookingEngine\BookingWebsiteDesignData;
use App\Data\BookingEngine\BookingWebsiteGeneralData;
use App\Data\BookingEngine\BookingWebsiteSeoData;
use App\Enums\AmenityType;
use App\Enums\BookingEngineMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingEngine\SaveBookingWebsiteDesignRequest;
use App\Http\Requests\BookingEngine\SaveBookingWebsiteGeneralRequest;
use App\Http\Requests\BookingEngine\SaveBookingWebsiteSeoRequest;
use App\Http\Requests\BookingEngine\SetAmenityRequest;
use App\Http\Requests\BookingEngine\UploadSiteImageRequest;
use App\Models\Property;
use App\Models\PropertyBookingSite;
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

    /**
     * The Design tab: template, main colour and one image per slot of the saved template. Microsite only.
     */
    public function design(Property $property, SetUpBookingSite $setUpBookingSite): Response
    {
        $site = $this->micrositeOrFail($setUpBookingSite($property));

        return Inertia::render('settings/booking-website/design', [
            'design' => BookingWebsiteDesignData::fromSite($site->load(['property', 'siteTemplate.slots'])),
        ]);
    }

    public function updateDesign(SaveBookingWebsiteDesignRequest $request, Property $property, SetUpBookingSite $setUpBookingSite, SaveBookingWebsiteDesign $saveBookingWebsiteDesign): RedirectResponse
    {
        $site = $this->micrositeOrFail($setUpBookingSite($property));

        Inertia::flash('toast', $saveBookingWebsiteDesign($site, $request->user(), $request->templateId(), $request->mainColor())
            ? ['type' => 'success', 'message' => __('Design saved.')]
            : ['type' => 'error', 'message' => __('That template is not available. Choose another one.')]);

        return to_route('settings.booking-website.design', $property);
    }

    public function uploadImage(UploadSiteImageRequest $request, Property $property, string $slot, SetUpBookingSite $setUpBookingSite, UploadSiteImage $uploadSiteImage): RedirectResponse
    {
        $site = $this->micrositeOrFail($setUpBookingSite($property));

        Inertia::flash('toast', $uploadSiteImage($site, $request->user(), $slot, $request->file('image'))
            ? ['type' => 'success', 'message' => __('Image saved.')]
            : ['type' => 'error', 'message' => __('The template has no such image slot. Reload the page and try again.')]);

        return back();
    }

    public function removeImage(Request $request, Property $property, string $slot, SetUpBookingSite $setUpBookingSite, RemoveSiteImage $removeSiteImage): RedirectResponse
    {
        $site = $this->micrositeOrFail($setUpBookingSite($property));

        Inertia::flash('toast', $removeSiteImage($site, $request->user(), $slot)
            ? ['type' => 'success', 'message' => __('Image removed.')]
            : ['type' => 'error', 'message' => __('The image could not be removed. Reload the page and try again.')]);

        return back();
    }

    /**
     * The SEO tab: the Google title and description. Microsite only.
     */
    public function seo(Property $property, SetUpBookingSite $setUpBookingSite): Response
    {
        $site = $this->micrositeOrFail($setUpBookingSite($property));

        return Inertia::render('settings/booking-website/seo', [
            'seo' => BookingWebsiteSeoData::fromModels($property->refresh(), $site),
        ]);
    }

    public function updateSeo(SaveBookingWebsiteSeoRequest $request, Property $property, SetUpBookingSite $setUpBookingSite, SaveBookingWebsiteSeo $saveBookingWebsiteSeo): RedirectResponse
    {
        $saveBookingWebsiteSeo($this->micrositeOrFail($setUpBookingSite($property)), $request->user(), $request->title(), $request->description());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('SEO saved.')]);

        return to_route('settings.booking-website.seo', $property);
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

    /**
     * Design and SEO belong to the microsite. An API only property has no such pages.
     */
    private function micrositeOrFail(PropertyBookingSite $site): PropertyBookingSite
    {
        abort_unless($site->mode === BookingEngineMode::Microsite, 404);

        return $site;
    }
}
