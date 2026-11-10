<?php

namespace App\Http\Controllers\Boats;

use App\Actions\Boats\SaveBoat;
use App\Data\Boats\BoatData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Boats\BoatRequest;
use App\Models\Boat;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Boats › Boats: the boats picked for each departure on the manifest.
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
                ->orderBy('name')
                ->get()
                ->map(fn (Boat $boat): BoatData => BoatData::fromModel($boat))
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
        $wasActive = $boat->active;

        $boat = $saveBoat($request->user(), $property, $request->boatAttributes(), $boat);

        // The Available switch sends the full row with a new `active`, so the toast names the change.
        $message = match (true) {
            $boat->active && ! $wasActive => __(':name is available.', ['name' => $boat->name]),
            ! $boat->active && $wasActive => __(':name is unavailable.', ['name' => $boat->name]),
            default => __('Boat saved.'),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
