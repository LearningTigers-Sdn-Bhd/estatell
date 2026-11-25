<?php

namespace App\Http\Controllers\Transfers;

use App\Actions\Transfers\SaveTransferVehicle;
use App\Data\Transfers\TransferVehicleData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\TransferVehicleRequest;
use App\Models\Property;
use App\Models\TransferVehicle;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Transfers › Vehicles: picked for each transfer on the run sheet.
 * See docs/35-transfers/01-land-transfers.md#settings.
 */
class TransferVehicleController extends Controller
{
    public function index(Property $property): Response
    {
        return Inertia::render('settings/transfers/vehicles', [
            'vehicles' => TransferVehicle::query()
                ->where('property_id', $property->id)
                ->orderBy('name')
                ->get()
                ->map(fn (TransferVehicle $item): TransferVehicleData => TransferVehicleData::fromModel($item))
                ->all(),
        ]);
    }

    public function store(TransferVehicleRequest $request, Property $property, SaveTransferVehicle $save): RedirectResponse
    {
        $save($request->user(), $property, $request->vehicleAttributes());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Vehicle added.')]);

        return back();
    }

    public function update(TransferVehicleRequest $request, Property $property, TransferVehicle $transferVehicle, SaveTransferVehicle $save): RedirectResponse
    {
        $wasActive = $transferVehicle->active;
        $item = $save($request->user(), $property, $request->vehicleAttributes(), $transferVehicle);

        $message = match (true) {
            $item->active && ! $wasActive => __(':name is available.', ['name' => $item->name]),
            ! $item->active && $wasActive => __(':name is unavailable.', ['name' => $item->name]),
            default => __('Vehicle saved.'),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
