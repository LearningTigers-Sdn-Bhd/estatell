<?php

namespace App\Http\Controllers\Catalogues;

use App\Actions\Catalogues\CreateChargeItem;
use App\Actions\Catalogues\ReorderChargeItems;
use App\Actions\Catalogues\UpdateChargeItem;
use App\Data\Catalogues\ChargeItemData;
use App\Data\Catalogues\ChargeTransactionCodeData;
use App\Enums\ChargingUnit;
use App\Enums\TransactionKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogues\ChargeItemRequest;
use App\Http\Requests\Catalogues\ReorderChargeItemsRequest;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\TransactionCode;
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

        $chargeItems = $property->chargeItems()
            ->with('transactionCode')
            ->orderByDesc('active')
            ->ordered()
            ->get();

        $currentTransactionCodeIds = $chargeItems->pluck('transaction_code_id')->unique();

        return Inertia::render('settings/charge-items/index', [
            'chargeItems' => $chargeItems->map(fn (ChargeItem $chargeItem): ChargeItemData => ChargeItemData::fromModel($chargeItem)),
            'transactionCodes' => $property->transactionCodes()
                ->where('kind', TransactionKind::Charge)
                ->where(function ($query) use ($currentTransactionCodeIds): void {
                    $query->active()->orWhereIn('id', $currentTransactionCodeIds);
                })
                ->orderByDesc('active')
                ->orderBy('code')
                ->get()
                ->map(fn (TransactionCode $transactionCode): ChargeTransactionCodeData => ChargeTransactionCodeData::fromModel($transactionCode)),
            'chargingUnits' => ChargingUnit::options(),
            'currency' => $property->settings->currency,
        ]);
    }

    public function store(ChargeItemRequest $request, Property $property, CreateChargeItem $createChargeItem): RedirectResponse
    {
        $chargeItem = $createChargeItem($property, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created.', ['name' => $chargeItem->name])]);

        return to_route('settings.charge-items.index', $property);
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
