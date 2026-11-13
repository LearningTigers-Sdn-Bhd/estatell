<?php

namespace App\Http\Controllers\Concierge;

use App\Actions\BookingEngine\SetPropertyAmenity;
use App\Actions\Concierge\AddFaq;
use App\Actions\Concierge\AddInfoTopic;
use App\Actions\Concierge\AddWifiNetwork;
use App\Actions\Concierge\RemoveConciergeHero;
use App\Actions\Concierge\RemoveFaq;
use App\Actions\Concierge\RemoveInfoTopic;
use App\Actions\Concierge\RemoveWifiNetwork;
use App\Actions\Concierge\SaveAmenityDetail;
use App\Actions\Concierge\SaveGuestContacts;
use App\Actions\Concierge\SaveHotelInfo;
use App\Actions\Concierge\UpdateConciergeSettings;
use App\Actions\Concierge\UpdateWifiNetwork;
use App\Actions\Concierge\UploadConciergeHero;
use App\Data\BookingEngine\AmenityPickerData;
use App\Data\BookingEngine\NearbyPlaceSuggestionData;
use App\Data\BookingEngine\PropertyNearbyPlaceData;
use App\Data\Concierge\ConciergeAmenitySettingsData;
use App\Data\Concierge\ConciergeContactSettingsData;
use App\Data\Concierge\ConciergeFaqData;
use App\Data\Concierge\ConciergeHotelInfoSettingsData;
use App\Data\Concierge\ConciergeTopicData;
use App\Data\Concierge\ConciergeWifiData;
use App\Enums\AmenityType;
use App\Enums\ParkingAvailability;
use App\Enums\ParkingPriceUnit;
use App\Enums\ParkingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingEngine\SetAmenityRequest;
use App\Http\Requests\Concierge\SaveAmenityDetailRequest;
use App\Http\Requests\Concierge\SaveGuestContactsRequest;
use App\Http\Requests\Concierge\SaveHotelInfoRequest;
use App\Http\Requests\Concierge\StoreFaqRequest;
use App\Http\Requests\Concierge\StoreInfoTopicRequest;
use App\Http\Requests\Concierge\StoreWifiRequest;
use App\Http\Requests\Concierge\UpdateSettingsRequest;
use App\Http\Requests\Concierge\UpdateWifiRequest;
use App\Http\Requests\Concierge\UploadHeroRequest;
use App\Models\Amenity;
use App\Models\NearbyPlace;
use App\Models\Property;
use App\Models\PropertyFaq;
use App\Models\PropertyInfoTopic;
use App\Models\PropertyWifiNetwork;
use App\Services\BookingEngine\NearbyPlaceSuggestions;
use App\Services\Concierge\ConciergeGuide;
use App\Services\Concierge\ConciergeLobbyQr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff side of the Concierge page: the lobby QR, the switches and the content guests read (house rules, Wi-Fi
 * networks and FAQs).
 */
class ConciergeSettingsController extends Controller
{
    /**
     * The staff page with the lobby address and its QR code to print.
     */
    public function index(Request $request, Property $property, ConciergeLobbyQr $qr, ConciergeGuide $guide, NearbyPlaceSuggestions $suggestions): Response
    {
        $url = route('concierge.lobby', ['property' => $property->code]);

        $property->loadMissing('settings');

        return Inertia::render('settings/concierge/index', [
            'lobbyUrl' => $url,
            'lobbyQr' => $qr->dataUri($url),
            'walkInEnabled' => (bool) $property->settings?->concierge_walk_in_enabled,
            'refundRequestsEnabled' => (bool) $property->settings?->concierge_refund_requests_enabled,
            'heroUrl' => $property->heroUrl(),
            'houseRules' => $property->settings?->house_rules,
            'locationCheckEnabled' => (bool) $property->settings?->concierge_location_check_enabled,
            'hasLocation' => filled($property->latitude) && filled($property->longitude),
            'policyTexts' => [
                'roomPolicy' => $property->settings?->room_policy,
                'paymentPolicy' => $property->settings?->payment_policy,
                'otherPolicies' => $property->settings?->other_policies,
            ],
            'wifiNetworks' => $property->wifiNetworks()->get()->map(ConciergeWifiData::fromModel(...))->values(),
            'faqs' => $property->faqs()->get()->map(ConciergeFaqData::fromModel(...))->values(),
            'hotelInfo' => ConciergeHotelInfoSettingsData::fromProperty($property->loadMissing('transportDetail')),
            'parkingOptions' => [
                'availability' => ParkingAvailability::options(),
                'types' => ParkingType::options(),
                'priceUnits' => ParkingPriceUnit::options(),
            ],
            'contacts' => ConciergeContactSettingsData::fromProperty($property),
            'amenities' => AmenityPickerData::forType(AmenityType::Property, $property->amenities()->get()),
            'amenityDetails' => $property->amenities()->with('category')->orderBy('position')->get()->map(ConciergeAmenitySettingsData::fromModel(...))->values(),
            'page' => max(1, $request->integer('page', 1)),
            'listUrl' => $request->url(),
            'topics' => $property->infoTopics()->get()->map(ConciergeTopicData::fromModel(...))->values(),
            'policyTerms' => $guide->terms($property),
            'sections' => [
                'hasAddress' => filled($property->address_line1),
                'hasContact' => filled($property->contact_phone) || filled($property->contact_email),
                'amenities' => $property->amenities()->count(),
                'nearbyPlaces' => $property->nearbyPlaces()->wherePivot('active', true)->count(),
            ],
            'nearbyPlaces' => $suggestions->ownPlaces($property)->map(fn (NearbyPlace $place): PropertyNearbyPlaceData => PropertyNearbyPlaceData::fromModel($place))->values(),
            'suggestions' => $suggestions->for($property)->map(fn (NearbyPlace $place): NearbyPlaceSuggestionData => NearbyPlaceSuggestionData::fromModel($place))->values(),
        ]);
    }

    public function update(UpdateSettingsRequest $request, Property $property, UpdateConciergeSettings $updateSettings): RedirectResponse
    {
        $updateSettings($request->user(), $property, $request->boolean('walk_in_enabled'), $request->boolean('refund_requests_enabled'), $request->input('house_rules'), $request->safe()->only(UpdateConciergeSettings::POLICY_FIELDS), $request->has('location_check_enabled') ? $request->boolean('location_check_enabled') : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Concierge settings saved.')]);

        return back();
    }

    public function storeHero(UploadHeroRequest $request, Property $property, UploadConciergeHero $uploadHero): RedirectResponse
    {
        $uploadHero($request->user(), $property, $request->file('hero'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Hero photo uploaded.')]);

        return back();
    }

    public function destroyHero(Request $request, Property $property, RemoveConciergeHero $removeHero): RedirectResponse
    {
        if ($removeHero($request->user(), $property)) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Hero photo removed.')]);
        }

        return back();
    }

    public function storeWifi(StoreWifiRequest $request, Property $property, AddWifiNetwork $addNetwork): RedirectResponse
    {
        $network = $addNetwork($request->user(), $property, $request->string('name')->toString(), $request->input('password'), $request->input('note'), $request->boolean('active', true), $request->boolean('primary_network'));

        Inertia::flash('toast', $network === null
            ? ['type' => 'error', 'message' => __('A property can list up to :count Wi-Fi networks.', ['count' => AddWifiNetwork::MAX])]
            : ['type' => 'success', 'message' => __('Wi-Fi network added.')]);

        return back();
    }

    public function updateWifi(UpdateWifiRequest $request, Property $property, PropertyWifiNetwork $wifiNetwork, UpdateWifiNetwork $updateNetwork): RedirectResponse
    {
        $updateNetwork($request->user(), $wifiNetwork, $request->boolean('active'), $request->boolean('primary_network'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Wi-Fi network updated.')]);

        return back();
    }

    public function destroyWifi(Request $request, Property $property, PropertyWifiNetwork $wifiNetwork, RemoveWifiNetwork $removeNetwork): RedirectResponse
    {
        $removeNetwork($request->user(), $wifiNetwork);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Wi-Fi network removed.')]);

        return back();
    }

    public function storeFaq(StoreFaqRequest $request, Property $property, AddFaq $addFaq): RedirectResponse
    {
        $faq = $addFaq($request->user(), $property, $request->string('question')->toString(), $request->string('answer')->toString(), $request->input('topic'));

        Inertia::flash('toast', $faq === null
            ? ['type' => 'error', 'message' => __('A property can list up to :count questions.', ['count' => AddFaq::MAX])]
            : ['type' => 'success', 'message' => __('Question added.')]);

        return back();
    }

    public function destroyFaq(Request $request, Property $property, PropertyFaq $faq, RemoveFaq $removeFaq): RedirectResponse
    {
        $removeFaq($request->user(), $faq);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question removed.')]);

        return back();
    }

    public function updateHotelInfo(SaveHotelInfoRequest $request, Property $property, SaveHotelInfo $saveHotelInfo): RedirectResponse
    {
        $saveHotelInfo($request->user(), $property, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Hotel info saved.')]);

        return back();
    }

    public function updateContacts(SaveGuestContactsRequest $request, Property $property, SaveGuestContacts $saveContacts): RedirectResponse
    {
        $saveContacts($request->user(), $property, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contacts saved.')]);

        return back();
    }

    public function storeTopic(StoreInfoTopicRequest $request, Property $property, AddInfoTopic $addTopic): RedirectResponse
    {
        $topic = $addTopic($request->user(), $property, $request->string('title')->toString(), $request->string('body')->toString());

        Inertia::flash('toast', $topic === null
            ? ['type' => 'error', 'message' => __('A property can list up to :count extra topics.', ['count' => AddInfoTopic::MAX])]
            : ['type' => 'success', 'message' => __('Topic added.')]);

        return back();
    }

    public function destroyTopic(Request $request, Property $property, PropertyInfoTopic $infoTopic, RemoveInfoTopic $removeTopic): RedirectResponse
    {
        $removeTopic($request->user(), $infoTopic);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Topic removed.')]);

        return back();
    }

    /**
     * One switch of the amenity list. Same Action and rules as the Booking website page, so either screen edits the same list.
     */
    public function toggleAmenity(SetAmenityRequest $request, Property $property, SetPropertyAmenity $setPropertyAmenity): RedirectResponse
    {
        if (! $setPropertyAmenity($request->user(), $property, $request->integer('amenity_id'), $request->boolean('active'))) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The amenity list changed. Reload the page and try again.')]);
        }

        return back();
    }

    public function updateAmenity(SaveAmenityDetailRequest $request, Property $property, Amenity $amenity, SaveAmenityDetail $saveDetail): RedirectResponse
    {
        $saved = $saveDetail($request->user(), $property, $amenity, $request->validated());

        Inertia::flash('toast', $saved
            ? ['type' => 'success', 'message' => __(':name details saved.', ['name' => $amenity->name])]
            : ['type' => 'error', 'message' => __('The property no longer offers this amenity. Reload the page.')]);

        return back();
    }
}
