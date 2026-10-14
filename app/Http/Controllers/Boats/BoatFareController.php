<?php

namespace App\Http\Controllers\Boats;

use App\Actions\Boats\CreateBoatFare;
use App\Actions\Boats\UpdateBoatFare;
use App\Data\Boats\BoatFareAgeGroupData;
use App\Data\Boats\BoatFareRowData;
use App\Data\Catalogues\ChargeItemTaxData;
use App\Data\Property\CurrencyData;
use App\Enums\AgeBandPricing;
use App\Enums\ChargingUnit;
use App\Enums\TransactionCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Boats\BoatFareRequest;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\Tax;
use App\Services\Boats\BoatFareUsage;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BoatFareController extends Controller
{
    /**
     * Sends every Transport charge item of the property as a fare, in the charge item order, with its usage. The
     * property's age groups and taxes come once, for the Sheet.
     */
    public function index(Property $property, BoatFareUsage $fareUsage): Response
    {
        abort_if($property->settings === null, 404);

        $usage = $fareUsage->availableCounts($property);
        $ageGroups = $property->ageGroups()->ordered()->get();

        return Inertia::render('settings/boats/fares', [
            'fares' => $property->chargeItems()
                ->whereHas('transactionCode', fn ($query) => $query->where('category', TransactionCategory::Transport->value))
                ->with(['transactionCode.applicableTaxes', 'childPrices'])
                ->orderByDesc('active')
                ->ordered()
                ->get()
                ->map(fn (ChargeItem $chargeItem): BoatFareRowData => BoatFareRowData::fromModel($chargeItem, $usage[$chargeItem->id] ?? 0, $ageGroups, $property->settings->currency))
                ->all(),
            'ageGroups' => $ageGroups
                ->map(fn (PropertyAgeGroup $ageGroup): BoatFareAgeGroupData => BoatFareAgeGroupData::fromModel($ageGroup))
                ->all(),
            'taxes' => $property->taxes()
                ->orderByDesc('enabled')
                ->orderBy('name')
                ->get()
                ->map(fn (Tax $tax): ChargeItemTaxData => ChargeItemTaxData::fromModel($tax))
                ->all(),
            'pricings' => AgeBandPricing::options(),
            'chargingUnits' => ChargingUnit::options(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    public function store(BoatFareRequest $request, Property $property, CreateBoatFare $createBoatFare): RedirectResponse
    {
        $fare = $createBoatFare($property, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $fare->name])]);

        return to_route('settings.boats.fares.index', $property);
    }

    /**
     * The Available switch and the fare Sheet both send the fare's values; only the switch sends `active`. A refusal (an available departure uses
     * the fare, on turn-off) flashes an error and saves nothing.
     */
    public function update(BoatFareRequest $request, Property $property, ChargeItem $chargeItem, UpdateBoatFare $updateBoatFare): RedirectResponse
    {
        abort_unless($chargeItem->transactionCode->category === TransactionCategory::Transport, 404);

        $wasActive = $chargeItem->active;

        if (! $updateBoatFare($request->user(), $chargeItem, $request->validated())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This fare is used by an available departure. Change their fare first.')]);

            return to_route('settings.boats.fares.index', $property);
        }

        $chargeItem->refresh();
        $isActive = $request->validated('active', $wasActive);

        if ($isActive !== $wasActive) {
            $message = $isActive
                ? __(':name is available.', ['name' => $chargeItem->name])
                : __(':name is unavailable.', ['name' => $chargeItem->name]);

            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Fare saved.')]);
        }

        return to_route('settings.boats.fares.index', $property);
    }
}
