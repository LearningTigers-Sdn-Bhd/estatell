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
use App\Actions\Concierge\SaveGettingHere;
use App\Actions\Concierge\SaveGuestContacts;
use App\Actions\Concierge\SaveGuestGuidelines;
use App\Actions\Concierge\SetFaqActive;
use App\Actions\Concierge\SetInfoTopicActive;
use App\Actions\Concierge\SetWifiNetworkActive;
use App\Actions\Concierge\UpdateConciergeSettings;
use App\Actions\Concierge\UpdateFaq;
use App\Actions\Concierge\UpdateInfoTopic;
use App\Actions\Concierge\UpdateWifiNetwork;
use App\Actions\Concierge\UploadConciergeHero;
use App\Data\BookingEngine\AmenityPickerData;
use App\Data\BookingEngine\NearbyPlaceSuggestionData;
use App\Data\BookingEngine\PropertyNearbyPlaceData;
use App\Data\Concierge\ConciergeAmenitySettingsData;
use App\Data\Concierge\ConciergeContactSettingsData;
use App\Data\Concierge\ConciergeFaqData;
use App\Data\Concierge\ConciergeGettingHereData;
use App\Data\Concierge\ConciergeGuestGuidelinesData;
use App\Data\Concierge\ConciergeProfileData;
use App\Data\Concierge\ConciergeTopicData;
use App\Data\Concierge\ConciergeWifiData;
use App\Enums\AmenityType;
use App\Enums\ParkingAvailability;
use App\Enums\ParkingPriceUnit;
use App\Enums\ParkingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingEngine\SetAmenityRequest;
use App\Http\Requests\Concierge\SaveAmenityDetailRequest;
use App\Http\Requests\Concierge\SaveGettingHereRequest;
use App\Http\Requests\Concierge\SaveGuestContactsRequest;
use App\Http\Requests\Concierge\SaveGuestGuidelinesRequest;
use App\Http\Requests\Concierge\SetFaqActiveRequest;
use App\Http\Requests\Concierge\SetInfoTopicActiveRequest;
use App\Http\Requests\Concierge\SetWifiNetworkActiveRequest;
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
 * The staff side of the Concierge page: the lobby QR, the switches and the content guests read. Each tab is its own
 * URL and sends only its own data.
 */
class ConciergeSettingsController extends Controller
{
    public function __construct(private ConciergeLobbyQr $qr) {}

    /**
     * Overview tab: how complete the guest guide is, the lobby QR and photo, and the guest action switches.
     */
    public function index(Property $property): Response
    {
        $property->loadMissing('settings');

        return $this->renderTab($property, 'index', [
            'heroUrl' => $property->heroUrl(),
            'houseRules' => $property->settings?->house_rules,
            'walkInEnabled' => (bool) $property->settings?->concierge_walk_in_enabled,
            'refundRequestsEnabled' => (bool) $property->settings?->concierge_refund_requests_enabled,
            'locationCheckEnabled' => (bool) $property->settings?->concierge_location_check_enabled,
            'testPaymentsEnabled' => (bool) $property->settings?->concierge_test_payments_enabled,
            'hasLocation' => filled($property->latitude) && filled($property->longitude),
            'sections' => [
                'hasArrivalInstructions' => filled($property->settings?->arrival_instructions),
                'hasAddress' => filled($property->address_line1),
                'hasContact' => filled($property->contact_phone) || filled($property->contact_email),
                'amenities' => $property->amenities()->count(),
                'nearbyPlaces' => $property->nearbyPlaces()->wherePivot('active', true)->count(),
                'faqs' => $property->faqs()->count(),
                'wifiNetworks' => $property->wifiNetworks()->count(),
            ],
        ]);
    }

    /**
     * Guest guidelines tab: the arrival and departure steps and the interesting topics, with how many topics are allowed.
     */
    public function guestGuidelines(Property $property): Response
    {
        return $this->renderTab($property, 'guest-guidelines', [
            'guidelines' => ConciergeGuestGuidelinesData::fromProperty($property->loadMissing('settings')),
            'topics' => $property->infoTopics()->get()->map(ConciergeTopicData::fromModel(...))->values(),
            'topicLimit' => AddInfoTopic::MAX,
        ]);
    }

    /**
     * Getting here tab: directions, travel times, transfers and parking, with the profile address shown read-only.
     */
    public function gettingHere(Property $property): Response
    {
        return $this->renderTab($property, 'getting-here', [
            'profile' => ConciergeProfileData::fromProperty($property),
            'gettingHere' => ConciergeGettingHereData::fromProperty($property->loadMissing('transportDetail')),
            'parkingOptions' => [
                'availability' => ParkingAvailability::options(),
                'types' => ParkingType::options(),
                'priceUnits' => ParkingPriceUnit::options(),
            ],
        ]);
    }

    public function policies(Property $property, ConciergeGuide $guide): Response
    {
        $property->loadMissing('settings');

        return $this->renderTab($property, 'policies', [
            'houseRules' => $property->settings?->house_rules,
            'walkInEnabled' => (bool) $property->settings?->concierge_walk_in_enabled,
            'refundRequestsEnabled' => (bool) $property->settings?->concierge_refund_requests_enabled,
            'policyTexts' => [
                'roomPolicy' => $property->settings?->room_policy,
                'paymentPolicy' => $property->settings?->payment_policy,
                'otherPolicies' => $property->settings?->other_policies,
            ],
            'policyTerms' => $guide->terms($property),
        ]);
    }

    public function faqs(Property $property): Response
    {
        return $this->renderTab($property, 'faqs', [
            'faqs' => $property->faqs()->get()->map(ConciergeFaqData::fromModel(...))->values(),
            'faqLimit' => AddFaq::MAX,
        ]);
    }

    public function amenities(Request $request, Property $property): Response
    {
        return $this->renderTab($property, 'amenities', [
            'amenities' => AmenityPickerData::forType(AmenityType::Property, $property->amenities()->get()),
            'amenityDetails' => $property->amenities()->with('category')->orderBy('position')->get()->map(ConciergeAmenitySettingsData::fromModel(...))->values(),
            'page' => max(1, $request->integer('page', 1)),
            'listUrl' => $request->url(),
        ]);
    }

    public function nearbyPlaces(Property $property, NearbyPlaceSuggestions $suggestions): Response
    {
        return $this->renderTab($property, 'nearby-places', [
            'nearbyPlaces' => $suggestions->ownPlaces($property)->map(fn (NearbyPlace $place): PropertyNearbyPlaceData => PropertyNearbyPlaceData::fromModel($place))->values(),
            'suggestions' => $suggestions->for($property)->map(fn (NearbyPlace $place): NearbyPlaceSuggestionData => NearbyPlaceSuggestionData::fromModel($place))->values(),
        ]);
    }

    public function wifi(Property $property): Response
    {
        return $this->renderTab($property, 'wifi', [
            'wifiNetworks' => $property->wifiNetworks()->get()->map(ConciergeWifiData::fromModel(...))->values(),
            'wifiLimit' => AddWifiNetwork::MAX,
        ]);
    }

    public function contacts(Property $property): Response
    {
        return $this->renderTab($property, 'contacts', [
            'profile' => ConciergeProfileData::fromProperty($property),
            'contacts' => ConciergeContactSettingsData::fromProperty($property),
        ]);
    }

    public function update(UpdateSettingsRequest $request, Property $property, UpdateConciergeSettings $updateSettings): RedirectResponse
    {
        $updateSettings($request->user(), $property, $request->boolean('walk_in_enabled'), $request->boolean('refund_requests_enabled'), $request->input('house_rules'), $request->safe()->only(UpdateConciergeSettings::POLICY_FIELDS), $request->has('location_check_enabled') ? $request->boolean('location_check_enabled') : null, $request->has('test_payments_enabled') ? $request->boolean('test_payments_enabled') : null);

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
        $updateNetwork($request->user(), $wifiNetwork, $request->string('name')->toString(), $request->input('password'), $request->input('note'), $request->boolean('primary_network'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Wi-Fi network saved.')]);

        return back();
    }

    public function wifiActive(SetWifiNetworkActiveRequest $request, Property $property, PropertyWifiNetwork $wifiNetwork, SetWifiNetworkActive $setNetworkActive): RedirectResponse
    {
        $setNetworkActive($request->user(), $wifiNetwork, $request->boolean('active'));

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

    public function updateFaq(StoreFaqRequest $request, Property $property, PropertyFaq $faq, UpdateFaq $updateFaq): RedirectResponse
    {
        $updateFaq($request->user(), $faq, $request->string('question')->toString(), $request->string('answer')->toString(), $request->input('topic'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question saved.')]);

        return back();
    }

    public function faqActive(SetFaqActiveRequest $request, Property $property, PropertyFaq $faq, SetFaqActive $setFaqActive): RedirectResponse
    {
        $setFaqActive($request->user(), $faq, $request->boolean('active'));

        return back();
    }

    public function destroyFaq(Request $request, Property $property, PropertyFaq $faq, RemoveFaq $removeFaq): RedirectResponse
    {
        $removeFaq($request->user(), $faq);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question removed.')]);

        return back();
    }

    public function updateGuestGuidelines(SaveGuestGuidelinesRequest $request, Property $property, SaveGuestGuidelines $saveGuestGuidelines): RedirectResponse
    {
        $saveGuestGuidelines($request->user(), $property, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Guest guidelines saved.')]);

        return back();
    }

    public function updateGettingHere(SaveGettingHereRequest $request, Property $property, SaveGettingHere $saveGettingHere): RedirectResponse
    {
        $saveGettingHere($request->user(), $property, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Getting here saved.')]);

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

    public function updateTopic(StoreInfoTopicRequest $request, Property $property, PropertyInfoTopic $infoTopic, UpdateInfoTopic $updateTopic): RedirectResponse
    {
        $updateTopic($request->user(), $infoTopic, $request->string('title')->toString(), $request->string('body')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Topic saved.')]);

        return back();
    }

    public function topicActive(SetInfoTopicActiveRequest $request, Property $property, PropertyInfoTopic $infoTopic, SetInfoTopicActive $setTopicActive): RedirectResponse
    {
        $setTopicActive($request->user(), $infoTopic, $request->boolean('active'));

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

    /**
     * Every tab gets the lobby address and QR for the header and the printed sheet, then its own data.
     *
     * @param  'index'|'guest-guidelines'|'getting-here'|'policies'|'faqs'|'amenities'|'nearby-places'|'wifi'|'contacts'  $page
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(Property $property, string $page, array $tabProps): Response
    {
        $url = route('concierge.lobby', ['property' => $property->code]);

        return Inertia::render("settings/concierge/{$page}", [
            'lobbyUrl' => $url,
            'lobbyQr' => $this->qr->dataUri($url),
            ...$tabProps,
        ]);
    }
}
