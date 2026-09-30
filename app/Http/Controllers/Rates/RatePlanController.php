<?php

namespace App\Http\Controllers\Rates;

use App\Actions\Rates\ArchiveRatePlan;
use App\Actions\Rates\CreateRatePlan;
use App\Actions\Rates\ReorderRatePlans;
use App\Actions\Rates\RestoreRatePlan;
use App\Actions\Rates\SaveRatePlanChildPrices;
use App\Actions\Rates\SyncRatePlanRoomTypes;
use App\Actions\Rates\UpdateRatePlan;
use App\Data\Rates\NewRatePlanRoomTypeData;
use App\Data\Rates\RatePlanChildPriceData;
use App\Data\Rates\RatePlanData;
use App\Data\Rates\RatePlanRoomTypeChoiceData;
use App\Enums\AgeBandPricing;
use App\Enums\PricingBasis;
use App\Enums\SellUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rates\ArchiveRatePlanRequest;
use App\Http\Requests\Rates\CreateRatePlanRequest;
use App\Http\Requests\Rates\RatePlanChildPricesRequest;
use App\Http\Requests\Rates\RatePlanRequest;
use App\Http\Requests\Rates\RatePlanRoomTypesRequest;
use App\Http\Requests\Rates\ReorderRatePlansRequest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RatePlanChildPrice;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RatePlanController extends Controller
{
    /**
     * Active rate plans in display order, then archived ones. The list is bounded, so it loads once.
     */
    public function index(Property $property): Response
    {
        return Inertia::render('settings/rate-plans/index', [
            'ratePlans' => $property->ratePlans()
                ->with('roomTypes')
                ->orderByRaw('archived_at IS NOT NULL')
                ->ordered()
                ->get()
                ->map(fn (RatePlan $ratePlan): RatePlanData => RatePlanData::fromModel($ratePlan)),
            'pricingBases' => array_values(array_filter(
                PricingBasis::options(),
                fn (array $option): bool => $option['value'] !== PricingBasis::Package->value,
            )),
            'roomTypes' => $property->roomTypes()->active()->ordered()->get()
                ->map(fn (RoomType $roomType): NewRatePlanRoomTypeData => NewRatePlanRoomTypeData::fromModel($roomType)),
        ]);
    }

    public function store(CreateRatePlanRequest $request, Property $property, CreateRatePlan $createRatePlan): RedirectResponse
    {
        $ratePlan = $createRatePlan($property, $request->user(), $request->ratePlanAttributes(), $request->roomTypeIds());

        if ($ratePlan === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The room types changed. Reload the page and try again.')]);

            return to_route('settings.rate-plans.index', $property);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created.', ['name' => $ratePlan->name])]);

        return $request->roomTypeIds() === []
            ? to_route('settings.rate-plans.room-types.edit', ['property' => $property, 'ratePlan' => $ratePlan])
            : to_route('settings.rate-plans.edit', ['property' => $property, 'ratePlan' => $ratePlan]);
    }

    /**
     * The Details tab of the plan editor. Each tab is its own URL and loads only its own data.
     */
    public function edit(Property $property, RatePlan $ratePlan): Response
    {
        return Inertia::render('settings/rate-plans/edit', [
            'ratePlan' => RatePlanData::fromModel($ratePlan->load('roomTypes')),
            'currency' => $property->settings->currency,
        ]);
    }

    /**
     * The Room types tab: every active room type, with whether this plan can sell it.
     */
    public function editRoomTypes(Property $property, RatePlan $ratePlan): Response
    {
        return Inertia::render('settings/rate-plans/room-types', [
            'ratePlan' => RatePlanData::fromModel($ratePlan->load('roomTypes')),
            'roomTypes' => $property->roomTypes()->active()->ordered()->get()
                ->map(fn (RoomType $roomType): RatePlanRoomTypeChoiceData => RatePlanRoomTypeChoiceData::forPlan($roomType, $ratePlan)),
            'sellUnits' => SellUnit::options(),
        ]);
    }

    /**
     * The Children tab, for per-guest plans only.
     */
    public function editChildPrices(Property $property, RatePlan $ratePlan): Response
    {
        abort_unless($ratePlan->pricing_basis === PricingBasis::PerGuest, 404);

        return Inertia::render('settings/rate-plans/child-prices', [
            'ratePlan' => RatePlanData::fromModel($ratePlan->load('roomTypes')),
            'childPrices' => $ratePlan->childPrices()->with('ageGroup')->get()
                ->sortBy('ageGroup.min_age')->values()
                ->map(fn (RatePlanChildPrice $childPrice): RatePlanChildPriceData => RatePlanChildPriceData::fromModel($childPrice)),
            'ageBandPricings' => AgeBandPricing::options(),
            'currency' => $property->settings->currency,
        ]);
    }

    public function update(RatePlanRequest $request, Property $property, RatePlan $ratePlan, UpdateRatePlan $updateRatePlan): RedirectResponse
    {
        Inertia::flash('toast', $updateRatePlan($request->user(), $ratePlan, $request->validated())
            ? ['type' => 'success', 'message' => __(':name saved.', ['name' => $request->string('name')->trim()])]
            : ['type' => 'error', 'message' => __(':name is archived. Restore it before editing.', ['name' => $ratePlan->name])]);

        return to_route('settings.rate-plans.edit', ['property' => $property, 'ratePlan' => $ratePlan]);
    }

    public function updateRoomTypes(RatePlanRoomTypesRequest $request, Property $property, RatePlan $ratePlan, SyncRatePlanRoomTypes $syncRatePlanRoomTypes): RedirectResponse
    {
        Inertia::flash('toast', $syncRatePlanRoomTypes($request->user(), $ratePlan, $request->roomTypeIds())
            ? ['type' => 'success', 'message' => __('Room types saved.')]
            : ['type' => 'error', 'message' => __('The rate plan or room types changed. Reload the page and try again.')]);

        return to_route('settings.rate-plans.room-types.edit', ['property' => $property, 'ratePlan' => $ratePlan]);
    }

    public function updateChildPrices(RatePlanChildPricesRequest $request, Property $property, RatePlan $ratePlan, SaveRatePlanChildPrices $saveRatePlanChildPrices): RedirectResponse
    {
        Inertia::flash('toast', $saveRatePlanChildPrices($request->user(), $ratePlan, $request->prices())
            ? ['type' => 'success', 'message' => __('Child prices saved.')]
            : ['type' => 'error', 'message' => __('The rate plan or age groups changed. Reload the page and try again.')]);

        return to_route('settings.rate-plans.child-prices.edit', ['property' => $property, 'ratePlan' => $ratePlan]);
    }

    public function reorder(ReorderRatePlansRequest $request, Property $property, ReorderRatePlans $reorderRatePlans): RedirectResponse
    {
        if (! $reorderRatePlans($property, $request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The rate plans changed. Reload the page and try again.')]);
        }

        return to_route('settings.rate-plans.index', $property);
    }

    public function archive(ArchiveRatePlanRequest $request, Property $property, RatePlan $ratePlan, ArchiveRatePlan $archiveRatePlan): RedirectResponse
    {
        Inertia::flash('toast', $archiveRatePlan($request->user(), $ratePlan)
            ? ['type' => 'success', 'message' => __(':name archived.', ['name' => $ratePlan->name])]
            : ['type' => 'error', 'message' => __(':name cannot be archived. Reload the page and try again.', ['name' => $ratePlan->name])]);

        return to_route('settings.rate-plans.index', $property);
    }

    public function restore(Request $request, Property $property, RatePlan $ratePlan, RestoreRatePlan $restoreRatePlan): RedirectResponse
    {
        Inertia::flash('toast', $restoreRatePlan($request->user(), $ratePlan)
            ? ['type' => 'success', 'message' => __(':name restored.', ['name' => $ratePlan->name])]
            : ['type' => 'error', 'message' => __(':name cannot be restored. Another rate plan uses the same name.', ['name' => $ratePlan->name])]);

        return to_route('settings.rate-plans.index', $property);
    }
}
