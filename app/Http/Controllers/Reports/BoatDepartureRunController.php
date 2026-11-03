<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Boats\SetBoatDepartureRun;
use App\Http\Controllers\Controller;
use App\Models\BoatDeparture;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Boats and crew on the Boat manifest: the boat and crew of one departure on the manifest date. See
 * docs/24-boats/03-boat-manifest.md#boats-and-crew.
 */
class BoatDepartureRunController extends Controller
{
    public function update(Request $request, Property $property, SetBoatDepartureRun $setRun): RedirectResponse
    {
        $validated = $request->validate([
            'boat_departure_id' => ['required', 'integer', Rule::exists('boat_departures', 'id')->where('property_id', $property->id)],
            'date' => ['required', 'date_format:Y-m-d'],
            'boat_id' => ['nullable', 'integer'],
            'crew' => ['nullable', 'string', 'max:120'],
        ]);
        $departure = BoatDeparture::query()->findOrFail((int) $validated['boat_departure_id']);
        $saved = $setRun(
            $request->user(),
            $departure,
            CarbonImmutable::createFromFormat('Y-m-d', $validated['date'])->startOfDay(),
            isset($validated['boat_id']) ? (int) $validated['boat_id'] : null,
            $validated['crew'] ?? null,
        );

        if (! $saved) {
            return back()->withErrors(['boat_id' => __('Pick an active boat.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boat and crew saved.')]);

        return back();
    }
}
