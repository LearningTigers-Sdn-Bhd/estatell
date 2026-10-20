<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Onboarding\CreatePropertyForOwner;
use App\Data\Channels\ChannelSyncProgressData;
use App\Data\Channels\ChannelTabData;
use App\Data\Platform\AdminPropertyData;
use App\Data\Platform\AdminPropertyRowData;
use App\Data\Platform\AdminPropertyStaffData;
use App\Data\Platform\PropertyFeatureData;
use App\Enums\OwnerSetupMethod;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Features\ChannelManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreatePropertyForOwnerRequest;
use App\Models\Property;
use App\Models\User;
use App\Services\Channels\ChannelStructurePreview;
use App\Services\Channels\ChannelSyncProgress;
use App\Services\Guests\CountryOptions;
use App\Services\Onboarding\GoLiveRequests;
use App\Services\Onboarding\OnboardingBoard;
use App\Services\Platform\PartnerDirectory;
use App\Services\Platform\PropertyDirectory;
use App\Services\Platform\PropertyOtaLists;
use App\Services\Staff\PropertyOwners;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;
use Spatie\LaravelData\PaginatedDataCollection;

class PropertyController extends Controller
{
    /**
     * List every property with search, filters and sorting in the URL.
     */
    public function index(Request $request, PropertyDirectory $propertyDirectory, PartnerDirectory $partnerDirectory, GoLiveRequests $goLiveRequests, OnboardingBoard $onboardingBoard): Response
    {
        $properties = $propertyDirectory->paginate($request);
        $waitingTab = $request->string('filter.status')->toString() === PropertyStatus::PendingApproval->value;
        $waiting = $waitingTab ? $goLiveRequests->waitingRows($properties->items()) : [];
        $onboardingTab = $request->string('filter.status')->toString() === PropertyStatus::Onboarding->value;
        $onboarding = $onboardingTab ? $onboardingBoard->rows($properties->items()) : [];

        return Inertia::render('admin/properties/index', [
            'properties' => AdminPropertyRowData::collect($properties, PaginatedDataCollection::class),
            'waiting' => $waiting,
            'onboarding' => $onboarding,
            'filters' => [
                'search' => $request->string('filter.search')->toString(),
                'status' => $request->string('filter.status')->toString(),
                'type' => $request->string('filter.type')->toString(),
                'partner' => $request->string('filter.partner')->toString(),
            ],
            'sort' => $request->string('sort', '-created_at')->toString(),
            'statuses' => PropertyStatus::options(),
            'types' => PropertyType::options(),
            'countries' => app(CountryOptions::class)->optionsWithMalaysiaFirst(),
            'partners' => $partnerDirectory->filterOptions(),
            'setupMethods' => OwnerSetupMethod::options(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Create an onboarding property and its owner.
     */
    public function store(CreatePropertyForOwnerRequest $request, CreatePropertyForOwner $createPropertyForOwner): RedirectResponse
    {
        $property = $createPropertyForOwner($request->user(), $request->toNewOwnerPropertyData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property and owner created.')]);

        return to_route('admin.properties.show', $property->code);
    }

    /**
     * Property page, Overview tab.
     */
    public function show(Property $property): Response
    {
        return $this->renderTab($property, 'overview');
    }

    /**
     * Property page, Staff tab.
     */
    public function staff(Property $property): Response
    {
        // Admin routes have no permission team; set it so each user's roles load for this property.
        setPermissionsTeamId($property->id);

        return $this->renderTab($property, 'staff', [
            'staff' => $property->users()->with('roles')->orderBy('name')->get()
                ->map(fn (User $user): AdminPropertyStaffData => AdminPropertyStaffData::fromModel($user))
                ->all(),
        ]);
    }

    /**
     * Property page, Features tab.
     */
    public function features(Property $property): Response
    {
        return $this->renderTab($property, 'features', [
            'features' => PropertyFeatureData::forProperty($property),
        ]);
    }

    /**
     * Property page, OTA lists tab. Passwords are never sent here; Reveal fetches one on demand.
     */
    public function otaLists(Property $property, PropertyOtaLists $propertyOtaLists): Response
    {
        return $this->renderTab($property, 'ota-lists', [
            'otaLists' => $propertyOtaLists->forProperty($property),
        ]);
    }

    /**
     * Property page, Channels tab. Shown only while the Channel manager feature is on (the `channels` middleware).
     */
    public function channels(Property $property, ChannelStructurePreview $channelStructurePreview, ChannelSyncProgress $channelSyncProgress): Response
    {
        $property->load('settings');

        return $this->renderTab($property, 'channels', [
            'channel' => ChannelTabData::forProperty($property->channelProperty, $channelStructurePreview->forProperty($property), ChannelSyncProgressData::fromProgress($channelSyncProgress->forProperty($property->id), $channelSyncProgress->isWaiting($property->id))),
        ]);
    }

    /**
     * Each tab has its own URL, so the open tab survives a refresh. Only the open tab's data is sent.
     *
     * @param  'overview'|'staff'|'features'|'ota-lists'|'channels'  $tab
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(Property $property, string $tab, array $tabProps = []): Response
    {
        $property->loadCount('users')->load('partner');

        return Inertia::render('admin/properties/show', [
            'property' => AdminPropertyData::fromModel($property, app(PropertyOwners::class)->firstFor($property)),
            'tab' => $tab,
            'channelManagerOn' => Feature::for($property)->active(ChannelManager::class),
            'partnerOptions' => $property->partner_id === null ? app(PartnerDirectory::class)->moveOptions() : [],
            ...$tabProps,
        ]);
    }
}
