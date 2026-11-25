<?php

namespace App\Http\Controllers\Transfers;

use App\Actions\Transfers\SaveTransferDriver;
use App\Data\Transfers\TransferDriverData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\TransferDriverRequest;
use App\Models\Property;
use App\Models\TransferDriver;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Transfers › Drivers: picked for each transfer on the run sheet.
 * See docs/35-transfers/01-land-transfers.md#settings.
 */
class TransferDriverController extends Controller
{
    public function index(Property $property): Response
    {
        return Inertia::render('settings/transfers/drivers', [
            'drivers' => TransferDriver::query()
                ->where('property_id', $property->id)
                ->orderBy('name')
                ->get()
                ->map(fn (TransferDriver $item): TransferDriverData => TransferDriverData::fromModel($item))
                ->all(),
        ]);
    }

    public function store(TransferDriverRequest $request, Property $property, SaveTransferDriver $save): RedirectResponse
    {
        $save($request->user(), $property, $request->driverAttributes());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Driver added.')]);

        return back();
    }

    public function update(TransferDriverRequest $request, Property $property, TransferDriver $transferDriver, SaveTransferDriver $save): RedirectResponse
    {
        $wasActive = $transferDriver->active;
        $item = $save($request->user(), $property, $request->driverAttributes(), $transferDriver);

        $message = match (true) {
            $item->active && ! $wasActive => __(':name is available.', ['name' => $item->name]),
            ! $item->active && $wasActive => __(':name is unavailable.', ['name' => $item->name]),
            default => __('Driver saved.'),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
