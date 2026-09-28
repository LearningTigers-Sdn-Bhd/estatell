<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Onboarding\CreatePropertyForOwner;
use App\Data\Platform\AdminPropertyData;
use App\Data\Platform\AdminPropertyRowData;
use App\Data\Platform\AdminPropertyStaffData;
use App\Data\Platform\PropertyFeatureData;
use App\Enums\OwnerSetupMethod;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreatePropertyForOwnerRequest;
use App\Models\Property;
use App\Models\User;
use App\Services\Platform\PropertyDirectory;
use App\Services\Staff\PropertyOwners;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class PropertyController extends Controller
{
    /**
     * List every property with search, filters and sorting in the URL.
     */
    public function index(Request $request, PropertyDirectory $propertyDirectory): Response
    {
        return Inertia::render('admin/properties/index', [
            'properties' => AdminPropertyRowData::collect($propertyDirectory->paginate($request), PaginatedDataCollection::class),
            'filters' => [
                'search' => $request->string('filter.search')->toString(),
                'status' => $request->string('filter.status')->toString(),
                'type' => $request->string('filter.type')->toString(),
            ],
            'sort' => $request->string('sort', '-code')->toString(),
            'statuses' => PropertyStatus::options(),
            'types' => PropertyType::options(),
            'setupMethods' => OwnerSetupMethod::options(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Create an onboarding property and its owner.
     */
    public function store(CreatePropertyForOwnerRequest $request, CreatePropertyForOwner $createPropertyForOwner): RedirectResponse
    {
        $property = $createPropertyForOwner($request->user(), $request->validated());

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
     * Each tab has its own URL, so the open tab survives a refresh. Only the open tab's data is sent.
     *
     * @param  'overview'|'staff'|'features'  $tab
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(Property $property, string $tab, array $tabProps = []): Response
    {
        $property->loadCount('users');

        return Inertia::render('admin/properties/show', [
            'property' => AdminPropertyData::fromModel($property, app(PropertyOwners::class)->firstFor($property)),
            'tab' => $tab,
            ...$tabProps,
        ]);
    }
}
