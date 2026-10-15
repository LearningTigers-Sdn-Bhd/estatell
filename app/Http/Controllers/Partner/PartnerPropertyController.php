<?php

namespace App\Http\Controllers\Partner;

use App\Actions\Onboarding\CreatePropertyForOwner;
use App\Data\Platform\AdminPropertyData;
use App\Data\Platform\AdminPropertyStaffData;
use App\Data\Platform\PartnerPropertyRowData;
use App\Data\Platform\PropertyFeatureData;
use App\Enums\OwnerSetupMethod;
use App\Enums\PropertyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Partner\StorePartnerPropertyRequest;
use App\Models\Property;
use App\Models\User;
use App\Services\Guests\CountryOptions;
use App\Services\Platform\PartnerProperties;
use App\Services\Staff\PropertyOwners;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class PartnerPropertyController extends Controller
{
    /**
     * The partner's properties and the signup link card. The create form options are sent only when the admin
     * switched on "Can create properties".
     */
    public function index(Request $request, PartnerProperties $partnerProperties): Response
    {
        $partner = $request->user()->partner;

        return Inertia::render('partner/properties/index', [
            'partnerName' => $partner->name,
            'signupUrl' => route('join', $partner->signup_code),
            'canCreateProperties' => $partner->can_create_properties,
            'properties' => PartnerPropertyRowData::collect($partnerProperties->paginate($partner), PaginatedDataCollection::class),
            ...($partner->can_create_properties ? [
                'types' => PropertyType::options(),
                'countries' => app(CountryOptions::class)->optionsWithMalaysiaFirst(),
                'setupMethods' => OwnerSetupMethod::options(),
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
            ] : []),
        ]);
    }

    /**
     * Create an onboarding property and its owner with the partner's code prefix.
     */
    public function store(StorePartnerPropertyRequest $request, CreatePropertyForOwner $createPropertyForOwner): RedirectResponse
    {
        $user = $request->user();
        $property = $createPropertyForOwner($user, $request->toNewOwnerPropertyData(), $user->partner);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Property and owner created.')]);

        return to_route('partner.properties.show', $property->code);
    }

    /**
     * Property page, Overview tab.
     */
    public function show(Request $request, PartnerProperties $partnerProperties, string $code): Response
    {
        return $this->renderTab($partnerProperties->findByCode($request->user()->partner, $code), 'overview');
    }

    /**
     * Property page, Staff tab (read-only).
     */
    public function staff(Request $request, PartnerProperties $partnerProperties, string $code): Response
    {
        $property = $partnerProperties->findByCode($request->user()->partner, $code);

        // Partner routes have no permission team; set it so each user's roles load for this property.
        setPermissionsTeamId($property->id);

        return $this->renderTab($property, 'staff', [
            'staff' => $property->users()->with('roles')->orderBy('name')->get()
                ->map(fn (User $user): AdminPropertyStaffData => AdminPropertyStaffData::forPartner($user))
                ->all(),
        ]);
    }

    /**
     * Property page, Features tab (read-only).
     */
    public function features(Request $request, PartnerProperties $partnerProperties, string $code): Response
    {
        $property = $partnerProperties->findByCode($request->user()->partner, $code);

        return $this->renderTab($property, 'features', [
            'features' => PropertyFeatureData::forProperty($property),
        ]);
    }

    /**
     * Each tab has its own URL, so the open tab survives a refresh. Only the open tab's data is sent.
     *
     * @param  'overview'|'staff'|'features'  $tab
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(Property $property, string $tab, array $tabProps = []): Response
    {
        $property->loadCount('users')->load('partner');

        return Inertia::render('partner/properties/show', [
            'property' => AdminPropertyData::fromModel($property, app(PropertyOwners::class)->firstFor($property)),
            'tab' => $tab,
            ...$tabProps,
        ]);
    }
}
