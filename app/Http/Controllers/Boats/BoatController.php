<?php

namespace App\Http\Controllers\Boats;

use App\Actions\Boats\SaveBoat;
use App\Actions\Boats\SetCharterChargeItem;
use App\Data\Boats\BoatData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Boats\BoatRequest;
use App\Models\Boat;
use App\Models\ChargeItem;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Boats › Boats: the boats picked for each departure on the manifest, and the charge a Charter trip adds.
 * See docs/24-boats/01-boat-settings.md#boats.
 */
class BoatController extends Controller
{
    public function index(Property $property): Response
    {
        abort_if($property->settings === null, 404);

        return Inertia::render('settings/boats/boats', [
            'boats' => Boat::query()
                ->where('property_id', $property->id)
                ->orderByDesc('active')
                ->orderBy('name')
                ->get()
                ->map(fn (Boat $boat): BoatData => BoatData::fromModel($boat))
                ->all(),
            'charterChargeItemId' => $property->settings->charter_charge_item_id,
            'chargeItems' => $property->chargeItems()
                ->active()
                ->whereNotNull('default_amount')
                ->ordered()
                ->get()
                ->map(fn (ChargeItem $item): array => ['value' => (string) $item->id, 'label' => $item->name])
                ->all(),
        ]);
    }

    public function store(BoatRequest $request, Property $property, SaveBoat $saveBoat): RedirectResponse
    {
        $saveBoat($request->user(), $property, $request->boatAttributes());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boat added.')]);

        return back();
    }

    public function update(BoatRequest $request, Property $property, Boat $boat, SaveBoat $saveBoat): RedirectResponse
    {
        $saveBoat($request->user(), $property, $request->boatAttributes(), $boat);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boat saved.')]);

        return back();
    }

    public function charter(Request $request, Property $property, SetCharterChargeItem $setCharterChargeItem): RedirectResponse
    {
        $validated = $request->validate(['charge_item_id' => ['nullable', 'integer']]);
        $itemId = isset($validated['charge_item_id']) ? (int) $validated['charge_item_id'] : null;

        if (! $setCharterChargeItem($request->user(), $property, $itemId)) {
            return back()->withErrors(['charge_item_id' => __('Pick an active charge item with a fixed price.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Charter boat charge saved.')]);

        return back();
    }
}
