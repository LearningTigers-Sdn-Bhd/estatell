<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Boats\SetBoatDepartureRun;
use App\Http\Controllers\Controller;
use App\Models\BoatDeparture;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Boats and crew on the Boat manifest: the boat and crew of departures on the manifest date. See
 * docs/24-boats/03-boat-manifest.md#boats-and-crew.
 */
class BoatDepartureRunController extends Controller
{
    public function update(Request $request, Property $property, SetBoatDepartureRun $setRun): RedirectResponse
    {
        if ($request->has('runs')) {
            $validated = $request->validate([
                'date' => ['required', 'date_format:Y-m-d'],
                'runs' => ['required', 'array'],
                'runs.*.boat_departure_id' => ['required', 'integer', Rule::exists('boat_departures', 'id')->where('property_id', $property->id)],
                'runs.*.boat_id' => ['nullable', 'integer'],
                'runs.*.crew' => ['nullable', 'string', 'max:120'],
                'runs.*.reset_to_default' => ['sometimes', 'boolean'],
            ]);

            $date = CarbonImmutable::createFromFormat('Y-m-d', $validated['date'])->startOfDay();

            DB::transaction(function () use ($request, $property, $validated, $date, $setRun): void {
                foreach ($validated['runs'] as $runData) {
                    $departure = BoatDeparture::query()->where('property_id', $property->id)->findOrFail((int) $runData['boat_departure_id']);
                    $saved = $setRun(
                        $request->user(),
                        $departure,
                        $date,
                        isset($runData['boat_id']) && $runData['boat_id'] !== '' ? (int) $runData['boat_id'] : null,
                        $runData['crew'] ?? null,
                        filter_var($runData['reset_to_default'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    );

                    if (! $saved) {
                        throw ValidationException::withMessages(['boat_id' => __('Pick an active boat.')]);
                    }
                }
            });

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Boats and crew saved.')]);

            return back();
        }

        $validated = $request->validate([
            'boat_departure_id' => ['required', 'integer', Rule::exists('boat_departures', 'id')->where('property_id', $property->id)],
            'date' => ['required', 'date_format:Y-m-d'],
            'boat_id' => ['nullable', 'integer'],
            'crew' => ['nullable', 'string', 'max:120'],
            'reset_to_default' => ['sometimes', 'boolean'],
        ]);
        $departure = BoatDeparture::query()->where('property_id', $property->id)->findOrFail((int) $validated['boat_departure_id']);
        $saved = $setRun(
            $request->user(),
            $departure,
            CarbonImmutable::createFromFormat('Y-m-d', $validated['date'])->startOfDay(),
            isset($validated['boat_id']) ? (int) $validated['boat_id'] : null,
            $validated['crew'] ?? null,
            filter_var($validated['reset_to_default'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );

        if (! $saved) {
            return back()->withErrors(['boat_id' => __('Pick an active boat.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boat and crew saved.')]);

        return back();
    }
}
