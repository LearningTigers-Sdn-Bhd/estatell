<?php

namespace App\Http\Controllers\Boats;

use App\Actions\Boats\CreateBoatDeparture;
use App\Actions\Boats\DeleteBoatDeparture;
use App\Actions\Boats\UpdateBoatDeparture;
use App\Data\Boats\BoatDepartureData;
use App\Data\Boats\BoatFareData;
use App\Data\Property\CurrencyData;
use App\Enums\BoatDirection;
use App\Enums\TransactionCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Boats\BoatDepartureRequest;
use App\Models\BoatDeparture;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Services\Boats\BoatTripUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BoatDepartureController extends Controller
{
    /**
     * The Boats settings item opens the departures. There are no other tabs yet.
     */
    public function redirectToDepartures(Property $property): RedirectResponse
    {
        return to_route('settings.boats.departures.index', $property);
    }

    /**
     * Sends every departure in leave-time order, with its fare, and the property's Transport charge items as fare choices.
     */
    public function index(Property $property, BoatTripUsage $boatTripUsage): Response
    {
        abort_if($property->settings === null, 404);

        $upcoming = $boatTripUsage->upcomingBookingCounts($property, $boatTripUsage->businessDate($property));

        return Inertia::render('settings/boats/departures', [
            'departures' => $property->boatDepartures()
                ->with('chargeItem')
                ->ordered()
                ->get()
                ->map(fn (BoatDeparture $boatDeparture): BoatDepartureData => BoatDepartureData::fromModel($boatDeparture, $upcoming[$boatDeparture->id] ?? 0))
                ->all(),
            'fares' => $property->chargeItems()
                ->whereHas('transactionCode', fn ($query) => $query->where('category', TransactionCategory::Transport->value))
                ->orderByDesc('active')
                ->ordered()
                ->get()
                ->map(fn (ChargeItem $chargeItem): BoatFareData => BoatFareData::fromModel($chargeItem))
                ->all(),
            'directions' => BoatDirection::options(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    public function store(BoatDepartureRequest $request, Property $property, CreateBoatDeparture $createBoatDeparture): RedirectResponse
    {
        $createBoatDeparture($property, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boat departure added.')]);

        return to_route('settings.boats.departures.index', $property);
    }

    /**
     * The Available switch sends the full row with a new `active`, so the toast names the change. Other saves say "saved".
     */
    public function update(BoatDepartureRequest $request, Property $property, BoatDeparture $boatDeparture, UpdateBoatDeparture $updateBoatDeparture): RedirectResponse
    {
        $wasActive = $boatDeparture->active;

        $result = $updateBoatDeparture($request->user(), $boatDeparture, $request->validated());

        if ($result->isRefused()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Boat trips use this departure, so its direction cannot change.')]);

            return to_route('settings.boats.departures.index', $property);
        }

        $boatDeparture->refresh();
        $isActive = $request->boolean('active', $wasActive);

        if ($isActive !== $wasActive) {
            $time = substr($boatDeparture->leaves_at, 0, 5);
            $direction = Str::lower($boatDeparture->direction->label());
            $message = $isActive
                ? __(':time :direction is available.', ['time' => $time, 'direction' => $direction])
                : __(':time :direction is unavailable.', ['time' => $time, 'direction' => $direction]);

            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Boat departure saved.')]);
        }

        return to_route('settings.boats.departures.index', $property);
    }

    public function destroy(Request $request, Property $property, BoatDeparture $boatDeparture, DeleteBoatDeparture $deleteBoatDeparture): RedirectResponse
    {
        $result = $deleteBoatDeparture($request->user(), $boatDeparture);

        if ($result->isRefused()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Boat trips use this departure. Turn it off instead.')]);

            return to_route('settings.boats.departures.index', $property);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boat departure deleted.')]);

        return to_route('settings.boats.departures.index', $property);
    }
}
