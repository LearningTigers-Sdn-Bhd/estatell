<?php

namespace App\Http\Controllers\Rates;

use App\Actions\Rates\ArchiveRatePlan;
use App\Actions\Rates\CopyParentStayDiscounts;
use App\Actions\Rates\CreateDerivedRatePlan;
use App\Actions\Rates\CreateRatePlan;
use App\Actions\Rates\ExtendRates;
use App\Actions\Rates\ReorderRatePlans;
use App\Actions\Rates\RestoreRatePlan;
use App\Actions\Rates\SavePackagePricing;
use App\Actions\Rates\SaveRatePlanChildPrices;
use App\Actions\Rates\SaveRatePlanDerivation;
use App\Actions\Rates\SaveRatePlanInclusions;
use App\Actions\Rates\SaveRatePlanStayDiscounts;
use App\Actions\Rates\SaveRates;
use App\Actions\Rates\SyncRatePlanRoomTypes;
use App\Actions\Rates\UpdateRatePlan;
use App\Data\Property\PropertyAgeGroupData;
use App\Data\Property\WeekendNightsOptionData;
use App\Data\Rates\NewRatePlanRoomTypeData;
use App\Data\Rates\PackagePriceData;
use App\Data\Rates\PackagePriceRoomTypeData;
use App\Data\Rates\RatePlanChildPriceData;
use App\Data\Rates\RatePlanData;
use App\Data\Rates\RatePlanDerivationData;
use App\Data\Rates\RatePlanDerivationPreviewRowData;
use App\Data\Rates\RatePlanInclusionChargeItemData;
use App\Data\Rates\RatePlanInclusionData;
use App\Data\Rates\RatePlanInclusionMealPeriodData;
use App\Data\Rates\RatePlanParentDiscountsData;
use App\Data\Rates\RatePlanRoomTypeChoiceData;
use App\Data\Rates\RatePlanStayDiscountData;
use App\Data\Rates\RatePricingRoomTypeData;
use App\Data\Rates\StayDiscountPreviewData;
use App\Enums\AgeBandPricing;
use App\Enums\ChargingUnit;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Enums\PackageUnit;
use App\Enums\PermissionEnum;
use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Enums\RatePlanStatus;
use App\Enums\SellUnit;
use App\Enums\StayDiscountType;
use App\Enums\Weekday;
use App\Enums\WeekendNights;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rates\ArchiveRatePlanRequest;
use App\Http\Requests\Rates\CreateDerivedRatePlanRequest;
use App\Http\Requests\Rates\CreateRatePlanRequest;
use App\Http\Requests\Rates\PackagePricingRequest;
use App\Http\Requests\Rates\RatePlanChildPricesRequest;
use App\Http\Requests\Rates\RatePlanDerivationRequest;
use App\Http\Requests\Rates\RatePlanDiscountsPreviewRequest;
use App\Http\Requests\Rates\RatePlanInclusionsRequest;
use App\Http\Requests\Rates\RatePlanRequest;
use App\Http\Requests\Rates\RatePlanRoomTypesRequest;
use App\Http\Requests\Rates\RatePlanStayDiscountsRequest;
use App\Http\Requests\Rates\ReorderRatePlansRequest;
use App\Http\Requests\Rates\SaveRatesRequest;
use App\Models\ChargeItem;
use App\Models\MealPeriod;
use App\Models\PackagePrice;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RatePlanChildPrice;
use App\Models\RatePlanInclusion;
use App\Models\RatePlanStayDiscount;
use App\Models\RoomType;
use App\Services\CityLedger\CompanyOptions;
use App\Services\Rates\DerivedRatePlans;
use App\Services\Rates\DerivedRatePreview;
use App\Services\Rates\RatesEndingCheck;
use App\Services\Rates\StayDiscountPreview;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RatePlanController extends Controller
{
    /**
     * Active rate plans in display order, then archived ones. The list is bounded, so it loads once.
     */
    public function index(Request $request, Property $property, CompanyOptions $companyOptions): Response
    {
        return Inertia::render('settings/rate-plans/index', [
            'ratePlans' => $property->ratePlans()
                ->with('roomTypes', 'parent')
                ->orderByRaw('archived_at IS NOT NULL')
                ->ordered()
                ->get()
                ->map(fn (RatePlan $ratePlan): RatePlanData => RatePlanData::fromModel($ratePlan)),
            'statuses' => RatePlanStatus::options(),
            'pricingBases' => PricingBasis::options(),
            'packageUnits' => PackageUnit::options(),
            'roomTypes' => $property->roomTypes()->active()->ordered()->get()
                ->map(fn (RoomType $roomType): NewRatePlanRoomTypeData => NewRatePlanRoomTypeData::fromModel($roomType)),
            'companies' => $request->user()->can(PermissionEnum::CompanyManage->value) ? $companyOptions->active($property) : [],
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
     * The Create derived plan Sheet: a plan that follows `$ratePlan`, with its room types and companies.
     */
    public function storeDerived(CreateDerivedRatePlanRequest $request, Property $property, RatePlan $ratePlan, CreateDerivedRatePlan $createDerivedRatePlan): RedirectResponse
    {
        $derived = $createDerivedRatePlan($request->user(), $ratePlan, $request->validated('name'), $request->derivation(), $request->signedValue(), $request->roomTypeIds(), $request->companyIds());

        Inertia::flash('toast', $derived === null
            ? ['type' => 'error', 'message' => __('The rate plan or its room types changed. Reload the page and try again.')]
            : ['type' => 'success', 'message' => __(':name created.', ['name' => $derived->name])]);

        return to_route('settings.rate-plans.index', $property);
    }

    /**
     * Shows today's price per room type of the parent with an unsaved derivation. Nothing is saved.
     */
    public function previewDerived(RatePlanDerivationRequest $request, Property $property, RatePlan $ratePlan, DerivedRatePreview $derivedRatePreview): JsonResponse
    {
        abort_if($ratePlan->archived_at !== null || $ratePlan->parent_rate_plan_id !== null || $ratePlan->pricing_basis === PricingBasis::Package, 404);

        if ($request->derivation() === null || $request->signedValue() === null) {
            return response()->json([]);
        }

        return response()->json(array_map(
            fn (array $row): RatePlanDerivationPreviewRowData => new RatePlanDerivationPreviewRowData($row['room_type_id'], $row['name'], $row['parent_amount'], $row['derived_amount']),
            $derivedRatePreview->preview($ratePlan->load('roomTypes'), $ratePlan, $request->derivation(), $request->signedValue(), now($property->settings->time_zone)->startOfDay(), $property->settings->currency->value),
        ));
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
     * The Children tab, for plans that price children by age group: per-guest plans and per-guest packages.
     */
    public function editChildPrices(Property $property, RatePlan $ratePlan): Response
    {
        abort_unless($ratePlan->pricing_basis->usesChildPrices($ratePlan->package_unit), 404);

        return Inertia::render('settings/rate-plans/child-prices', [
            'ratePlan' => RatePlanData::fromModel($ratePlan->load('roomTypes')),
            'childPrices' => $ratePlan->childPrices()->with('ageGroup')->get()
                ->sortBy('ageGroup.min_age')->values()
                ->map(fn (RatePlanChildPrice $childPrice): RatePlanChildPriceData => RatePlanChildPriceData::fromModel($childPrice)),
            'ageBandPricings' => AgeBandPricing::options(),
            'currency' => $property->settings->currency,
        ]);
    }

    /**
     * The Inclusions tab: what the plan's price includes. Charge items and meal periods that are off are listed
     * only when the plan already uses them.
     */
    public function editInclusions(Property $property, RatePlan $ratePlan): Response
    {
        $inclusions = $ratePlan->inclusions()->get();

        return Inertia::render('settings/rate-plans/inclusions', [
            'ratePlan' => RatePlanData::fromModel($ratePlan->load('roomTypes')),
            'inclusions' => $inclusions->map(fn (RatePlanInclusion $inclusion): RatePlanInclusionData => RatePlanInclusionData::fromModel($inclusion)),
            'chargeItems' => $property->chargeItems()
                ->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $inclusions->pluck('charge_item_id')))
                ->ordered()->get()
                ->map(fn (ChargeItem $chargeItem): RatePlanInclusionChargeItemData => RatePlanInclusionChargeItemData::fromModel($chargeItem)),
            'mealPeriods' => $property->mealPeriods()
                ->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $inclusions->pluck('meal_period_id')))
                ->ordered()->get()
                ->map(fn (MealPeriod $mealPeriod): RatePlanInclusionMealPeriodData => RatePlanInclusionMealPeriodData::fromModel($mealPeriod)),
            'chargingUnits' => ChargingUnit::options(),
            'startOptions' => InclusionStart::options(),
            'endOptions' => InclusionEnd::options(),
            'currency' => $property->settings->currency,
        ]);
    }

    /**
     * The Pricing tab of a package plan: the stay price by room type and arrival period, and the arrival weekdays.
     * A plan with no stored weekdays starts on any day, so all seven are ticked.
     */
    private function editPackagePricing(Property $property, RatePlan $ratePlan): Response
    {
        $stored = $ratePlan->arrivalWeekdays()->get()->map(fn ($row): string => $row->weekday->value)->all();

        return Inertia::render('settings/rate-plans/package-pricing', [
            'ratePlan' => RatePlanData::fromModel($ratePlan->load('roomTypes')),
            'roomTypes' => $ratePlan->roomTypes()->whereNull('room_types.archived_at')->orderBy('room_types.position')->orderBy('room_types.id')->get()
                ->map(fn (RoomType $roomType): PackagePriceRoomTypeData => PackagePriceRoomTypeData::fromModel($roomType)),
            'prices' => $ratePlan->packagePrices()->get()
                ->map(fn (PackagePrice $price): PackagePriceData => PackagePriceData::fromModel($price)),
            'weekdays' => $stored === [] ? array_map(fn (Weekday $day): string => $day->value, Weekday::cases()) : $stored,
            'weekdayOptions' => Weekday::options(),
            'currency' => $property->settings->currency,
        ]);
    }

    /**
     * The Discounts tab: long-stay discounts. Package plans have none.
     */
    public function editDiscounts(Property $property, RatePlan $ratePlan, StayDiscountPreview $stayDiscountPreview): Response
    {
        abort_if($ratePlan->pricing_basis === PricingBasis::Package, 404);

        $today = now($property->settings->time_zone)->startOfDay();
        $discounts = $ratePlan->stayDiscounts()->orderBy('min_nights')->get();
        $nights = $stayDiscountPreview->defaultNights($discounts);
        $parentDiscounts = $ratePlan->parent_rate_plan_id === null || $discounts->isNotEmpty()
            ? new Collection
            : $ratePlan->parent->stayDiscounts()->orderBy('min_nights')->get();

        return Inertia::render('settings/rate-plans/discounts', [
            'ratePlan' => RatePlanData::fromModel($ratePlan->load('roomTypes')),
            'discounts' => $discounts->map(fn (RatePlanStayDiscount $discount): RatePlanStayDiscountData => RatePlanStayDiscountData::fromModel($discount)),
            'discountTypes' => StayDiscountType::options(),
            'parentDiscounts' => $ratePlan->parent_rate_plan_id === null || $parentDiscounts->isEmpty()
                ? null
                : new RatePlanParentDiscountsData($ratePlan->parent->name),
            'currency' => $property->settings->currency,
            'ageGroups' => $ratePlan->pricing_basis === PricingBasis::PerGuest
                ? $property->ageGroups()->orderBy('min_age')->get()->map(PropertyAgeGroupData::fromModel(...))
                : [],
            'preview' => StayDiscountPreviewData::fromPreview(
                $today->toDateString(),
                $nights,
                $stayDiscountPreview->stayLengths($discounts),
                0,
                $stayDiscountPreview->preview($ratePlan, $discounts, $today, $nights, null, []),
            ),
        ]);
    }

    /**
     * Prices the linked room types with the unsaved rules of the Discounts tab. Nothing is saved.
     */
    public function previewDiscounts(RatePlanDiscountsPreviewRequest $request, Property $property, RatePlan $ratePlan, StayDiscountPreview $stayDiscountPreview): JsonResponse
    {
        abort_if($ratePlan->pricing_basis === PricingBasis::Package, 404);

        $rules = $request->validRules();
        $nights = $request->nights() ?? $stayDiscountPreview->defaultNights($rules);

        return response()->json(StayDiscountPreviewData::fromPreview(
            $request->arrival()->toDateString(),
            $nights,
            $stayDiscountPreview->stayLengths($rules),
            $request->ignoredRuleCount(),
            $stayDiscountPreview->preview($ratePlan, $rules, $request->arrival(), $nights, $request->adults(), $request->childrenByAgeGroup()),
        ));
    }

    /**
     * The Pricing tab. Package plans show their stay price grid instead of the nightly pricing form.
     */
    public function editPricing(Property $property, RatePlan $ratePlan, RatesEndingCheck $ratesEndingCheck, DerivedRatePlans $derivedRatePlans): Response
    {
        if ($ratePlan->pricing_basis === PricingBasis::Package) {
            return $this->editPackagePricing($property, $ratePlan);
        }

        $today = now($property->settings->time_zone);
        $pricedUntil = $ratesEndingCheck->pricedUntil($ratePlan);

        return Inertia::render('settings/rate-plans/pricing', [
            'ratePlan' => RatePlanData::fromModel($ratePlan->load('roomTypes')),
            'derivation' => RatePlanDerivationData::forPlan($ratePlan, $derivedRatePlans),
            'derivations' => RateDerivation::options(),
            'roomTypes' => $ratePlan->roomTypes()->whereNull('room_types.archived_at')->orderBy('room_types.position')->orderBy('room_types.id')->get()
                ->map(fn (RoomType $roomType): RatePricingRoomTypeData => RatePricingRoomTypeData::fromModel(
                    $roomType,
                    $pricedUntil[$roomType->id] ?? null,
                    $ratesEndingCheck->isEnding($pricedUntil[$roomType->id] ?? null, $today),
                )),
            'currency' => $property->settings->currency,
            'weekendNights' => $property->settings->weekend_nights,
            'weekendNightsOptions' => array_map(WeekendNightsOptionData::fromEnum(...), WeekendNights::cases()),
            'today' => $today->toDateString(),
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

    public function updateInclusions(RatePlanInclusionsRequest $request, Property $property, RatePlan $ratePlan, SaveRatePlanInclusions $saveRatePlanInclusions): RedirectResponse
    {
        Inertia::flash('toast', $saveRatePlanInclusions($request->user(), $ratePlan, $request->inclusions())
            ? ['type' => 'success', 'message' => __('Inclusions saved.')]
            : ['type' => 'error', 'message' => __('The rate plan, charge items or meal periods changed. Reload the page and try again.')]);

        return to_route('settings.rate-plans.inclusions.edit', ['property' => $property, 'ratePlan' => $ratePlan]);
    }

    public function updatePackagePricing(PackagePricingRequest $request, Property $property, RatePlan $ratePlan, SavePackagePricing $savePackagePricing): RedirectResponse
    {
        Inertia::flash('toast', $savePackagePricing($request->user(), $ratePlan, $request->prices(), $request->weekdays())
            ? ['type' => 'success', 'message' => __('Package pricing saved.')]
            : ['type' => 'error', 'message' => __('The rate plan or room types changed. Reload the page and try again.')]);

        return to_route('settings.rate-plans.pricing.edit', ['property' => $property, 'ratePlan' => $ratePlan]);
    }

    public function updateDiscounts(RatePlanStayDiscountsRequest $request, Property $property, RatePlan $ratePlan, SaveRatePlanStayDiscounts $saveRatePlanStayDiscounts): RedirectResponse
    {
        abort_if($ratePlan->pricing_basis === PricingBasis::Package, 404);

        Inertia::flash('toast', $saveRatePlanStayDiscounts($request->user(), $ratePlan, $request->discounts())
            ? ['type' => 'success', 'message' => __('Discounts saved.')]
            : ['type' => 'error', 'message' => __('The rate plan changed. Reload the page and try again.')]);

        return to_route('settings.rate-plans.discounts.edit', ['property' => $property, 'ratePlan' => $ratePlan]);
    }

    public function copyDiscounts(Request $request, Property $property, RatePlan $ratePlan, CopyParentStayDiscounts $copyParentStayDiscounts): RedirectResponse
    {
        Inertia::flash('toast', $copyParentStayDiscounts($request->user(), $ratePlan)
            ? ['type' => 'success', 'message' => __('Discounts copied.')]
            : ['type' => 'error', 'message' => __('The rate plan changed. Reload the page and try again.')]);

        return to_route('settings.rate-plans.discounts.edit', ['property' => $property, 'ratePlan' => $ratePlan]);
    }

    public function updatePricing(SaveRatesRequest $request, Property $property, RatePlan $ratePlan, SaveRates $saveRates): RedirectResponse
    {
        abort_if($ratePlan->pricing_basis === PricingBasis::Package, 404);

        $written = $saveRates($request->user(), $ratePlan, $request->string('from')->toString(), $request->string('to')->toString(), $request->roomTypes(), $request->restrictions());

        Inertia::flash('toast', $written === false
            ? ['type' => 'error', 'message' => __('The rate plan or room types changed. Reload the page and try again.')]
            : ['type' => 'success', 'message' => trans_choice('Prices saved for :count date.|Prices saved for :count dates.', $written)]);

        return to_route('settings.rate-plans.pricing.edit', ['property' => $property, 'ratePlan' => $ratePlan]);
    }

    public function updateDerivation(RatePlanDerivationRequest $request, Property $property, RatePlan $ratePlan, SaveRatePlanDerivation $saveRatePlanDerivation): RedirectResponse
    {
        abort_if($ratePlan->pricing_basis === PricingBasis::Package, 404);

        Inertia::flash('toast', $saveRatePlanDerivation($request->user(), $ratePlan, $request->parentId(), $request->derivation(), $request->signedValue())
            ? ['type' => 'success', 'message' => $request->parentId() === null ? __('This plan sets its own prices.') : __('Pricing saved.')]
            : ['type' => 'error', 'message' => __('The rate plan changed. Reload the page and try again.')]);

        return to_route('settings.rate-plans.pricing.edit', ['property' => $property, 'ratePlan' => $ratePlan]);
    }

    /**
     * Shows today's price per room type with an unsaved derivation. Nothing is saved.
     */
    public function previewDerivation(RatePlanDerivationRequest $request, Property $property, RatePlan $ratePlan, DerivedRatePlans $derivedRatePlans, DerivedRatePreview $derivedRatePreview): JsonResponse
    {
        abort_if($ratePlan->pricing_basis === PricingBasis::Package, 404);

        $parent = $request->parentId() === null
            ? null
            : $derivedRatePlans->eligibleParents($ratePlan)->firstWhere('id', $request->parentId());

        if ($parent === null || $request->derivation() === null || $request->signedValue() === null) {
            return response()->json([]);
        }

        return response()->json(array_map(
            fn (array $row): RatePlanDerivationPreviewRowData => new RatePlanDerivationPreviewRowData($row['room_type_id'], $row['name'], $row['parent_amount'], $row['derived_amount']),
            $derivedRatePreview->preview($ratePlan, $parent, $request->derivation(), $request->signedValue(), now($property->settings->time_zone)->startOfDay(), $property->settings->currency->value),
        ));
    }

    public function extendPricing(Request $request, Property $property, RatePlan $ratePlan, ExtendRates $extendRates): RedirectResponse
    {
        $written = $extendRates($request->user(), $ratePlan, now($property->settings->time_zone));

        Inertia::flash('toast', match (true) {
            $written === false => ['type' => 'error', 'message' => __(':name cannot extend its prices. Reload the page and try again.', ['name' => $ratePlan->name])],
            $written === 0 => ['type' => 'error', 'message' => __('There are no prices to copy yet. Set prices first.')],
            default => ['type' => 'success', 'message' => trans_choice('Prices extended by :count date.|Prices extended by :count dates.', $written)],
        });

        return to_route('settings.rate-plans.pricing.edit', ['property' => $property, 'ratePlan' => $ratePlan]);
    }

    public function reorder(ReorderRatePlansRequest $request, Property $property, ReorderRatePlans $reorderRatePlans): RedirectResponse
    {
        if (! $reorderRatePlans($property, $request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The rate plans changed. Reload the page and try again.')]);
        }

        return to_route('settings.rate-plans.index', $property);
    }

    public function archive(ArchiveRatePlanRequest $request, Property $property, RatePlan $ratePlan, ArchiveRatePlan $archiveRatePlan, DerivedRatePlans $derivedRatePlans): RedirectResponse
    {
        Inertia::flash('toast', $archiveRatePlan($request->user(), $ratePlan)
            ? ['type' => 'success', 'message' => __(':name archived.', ['name' => $ratePlan->name])]
            : ['type' => 'error', 'message' => $derivedRatePlans->followersMessage($ratePlan) ?? __(':name cannot be archived. Reload the page and try again.', ['name' => $ratePlan->name])]);

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
