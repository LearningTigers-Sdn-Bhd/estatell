<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SaveOnboardingChargeItems;
use App\Data\Catalogues\ChargeItemTaxData;
use App\Data\Onboarding\OnboardingChargeItemData;
use App\Data\Property\CurrencyData;
use App\Enums\ChargingUnit;
use App\Enums\OnboardingStep;
use App\Enums\TransactionCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SaveChargeItemsRequest;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\Tax;
use App\Services\Onboarding\ChargeItemTaxPreselection;
use App\Services\Onboarding\OnboardingProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChargeItemController extends Controller
{
    /**
     * The property's charge items without Transport (boat fares own those), the enabled taxes, the taxes a new row or a
     * starter item without tax starts with, and the codes already taken, so a new row's code can show its number. A
     * locked step opens as a preview. `from=taxes` marks the arrival from the Taxes hint, which highlights the Taxes column.
     */
    public function edit(Request $request, Property $property, OnboardingProgress $progress, ChargeItemTaxPreselection $preselection): Response
    {
        $blockingStep = $progress->blockingStep($property, OnboardingStep::ChargeItems);
        $presetTaxIds = $preselection->forProperty($property);

        return Inertia::render('onboarding/charge-items', [
            'chargeItems' => $property->chargeItems()
                ->with('transactionCode.applicableTaxes')
                ->whereHas('transactionCode', fn (Builder $query) => $query->where('category', '!=', TransactionCategory::Transport->value))
                ->ordered()
                ->get()
                ->map(fn (ChargeItem $chargeItem): OnboardingChargeItemData => OnboardingChargeItemData::fromModel($chargeItem, $presetTaxIds)),
            'taxes' => $property->taxes()
                ->where('enabled', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Tax $tax): ChargeItemTaxData => ChargeItemTaxData::fromModel($tax)),
            'presetTaxIds' => $presetTaxIds,
            'takenCodes' => $property->transactionCodes()->pluck('code')->map(fn (string $code): string => mb_strtoupper($code))->values()->all(),
            'categories' => TransactionCategory::onboardingChargeItemOptions(),
            'chargingUnits' => ChargingUnit::options(),
            'currency' => CurrencyData::forProperty($property),
            'maxRows' => SaveChargeItemsRequest::MAX_ROWS,
            'highlightTaxes' => $request->query('from') === 'taxes',
            'locked' => $blockingStep !== null,
            'blockedBy' => $blockingStep === null ? null : [
                'label' => $blockingStep->label(),
                'url' => $blockingStep->routeName() === null ? null : route($blockingStep->routeName(), ['property' => $property->code], absolute: false),
            ],
        ]);
    }

    public function update(SaveChargeItemsRequest $request, Property $property, SaveOnboardingChargeItems $saveChargeItems): RedirectResponse
    {
        $saveChargeItems($property, $request->user(), $request->rows());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Charge items saved.')]);

        $next = OnboardingStep::ChargeItems->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }
}
