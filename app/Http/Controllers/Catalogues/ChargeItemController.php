<?php

namespace App\Http\Controllers\Catalogues;

use App\Actions\Catalogues\CreateChargeItem;
use App\Actions\Catalogues\ReorderChargeItems;
use App\Actions\Catalogues\UpdateChargeItem;
use App\Data\Catalogues\ChargeItemChildPriceData;
use App\Data\Catalogues\ChargeItemData;
use App\Data\Catalogues\ChargeItemTaxData;
use App\Enums\AgeBandPricing;
use App\Enums\ChargingUnit;
use App\Enums\TransactionCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogues\ChargeItemRequest;
use App\Http\Requests\Catalogues\ReorderChargeItemsRequest;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\Tax;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ChargeItemController extends Controller
{
    /**
     * Load the property's bounded charge-item catalogue once for browser-side filtering.
     */
    public function index(Property $property): Response
    {
        abort_if($property->settings === null, 404);

        return Inertia::render('settings/charge-items/index', [
            'chargeItems' => $property->chargeItems()
                ->with('transactionCode.applicableTaxes')
                ->orderByDesc('active')
                ->ordered()
                ->get()
                ->map(fn (ChargeItem $chargeItem): ChargeItemData => ChargeItemData::fromModel($chargeItem)),
            'taxes' => $property->taxes()
                ->orderByDesc('enabled')
                ->orderBy('name')
                ->get()
                ->map(fn (Tax $tax): ChargeItemTaxData => ChargeItemTaxData::fromModel($tax)),
            'categories' => TransactionCategory::chargeItemOptions(),
            'chargingUnits' => ChargingUnit::options(),
            'currency' => $property->settings->currency,
        ]);
    }

    public function store(ChargeItemRequest $request, Property $property, CreateChargeItem $createChargeItem): RedirectResponse
    {
        $chargeItem = $createChargeItem($property, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created. Set its price and taxes.', ['name' => $chargeItem->name])]);

        return to_route('settings.charge-items.edit', [$property, $chargeItem]);
    }

    public function edit(Property $property, ChargeItem $chargeItem): Response
    {
        abort_if($property->settings === null, 404);

        $prices = $chargeItem->childPrices()->get()->keyBy('property_age_group_id');

        return Inertia::render('settings/charge-items/edit', [
            'chargeItem' => ChargeItemData::fromModel($chargeItem->load('transactionCode.applicableTaxes')),
            'childPrices' => $property->ageGroups()->ordered()->get()
                ->map(fn (PropertyAgeGroup $ageGroup): ChargeItemChildPriceData => ChargeItemChildPriceData::forAgeGroup($chargeItem, $ageGroup, $prices->get($ageGroup->id)))
                ->all(),
            'pricings' => AgeBandPricing::options(),
            'taxes' => $property->taxes()
                ->orderByDesc('enabled')
                ->orderBy('name')
                ->get()
                ->map(fn (Tax $tax): ChargeItemTaxData => ChargeItemTaxData::fromModel($tax)),
            'categories' => TransactionCategory::chargeItemOptions(),
            'chargingUnits' => ChargingUnit::options(),
            'currency' => $property->settings->currency,
        ]);
    }

    public function update(ChargeItemRequest $request, Property $property, ChargeItem $chargeItem, UpdateChargeItem $updateChargeItem): RedirectResponse
    {
        $updateChargeItem($request->user(), $chargeItem, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $chargeItem->name])]);

        return to_route('settings.charge-items.index', $property);
    }

    public function reorder(ReorderChargeItemsRequest $request, Property $property, ReorderChargeItems $reorderChargeItems): RedirectResponse
    {
        if (! $reorderChargeItems($property, $request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The charge items changed. Reload the page and try again.')]);
        }

        return to_route('settings.charge-items.index', $property);
    }
}
