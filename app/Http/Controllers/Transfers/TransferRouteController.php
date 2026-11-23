<?php

namespace App\Http\Controllers\Transfers;

use App\Actions\Transfers\SaveTransferRoute;
use App\Data\Property\CurrencyData;
use App\Data\Transfers\TransferRouteData;
use App\Enums\TransferDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\TransferRouteRequest;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\TransferRoute;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Transfers › Routes: the one-way road routes and their shared and private prices.
 * See docs/34-transfers/01-land-transfers.md#settings.
 */
class TransferRouteController extends Controller
{
    public function redirectToRoutes(Property $property): RedirectResponse
    {
        return to_route('settings.transfers.routes.index', ['property' => $property->code]);
    }

    public function index(Property $property): Response
    {
        return Inertia::render('settings/transfers/routes', [
            'routes' => TransferRoute::query()
                ->where('property_id', $property->id)
                ->with(['sharedChargeItem', 'privateChargeItem'])
                ->orderBy('direction')
                ->orderBy('name')
                ->get()
                ->map(fn (TransferRoute $route): TransferRouteData => TransferRouteData::fromModel($route))
                ->all(),
            'directions' => TransferDirection::options(),
            'chargeItems' => ChargeItem::query()
                ->where('property_id', $property->id)
                ->active()
                ->whereNotNull('default_amount')
                ->ordered()
                ->get()
                ->map(fn (ChargeItem $item): array => ['value' => (string) $item->id, 'label' => $item->name])
                ->all(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    public function store(TransferRouteRequest $request, Property $property, SaveTransferRoute $saveRoute): RedirectResponse
    {
        $saveRoute($request->user(), $property, $request->routeAttributes());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Route added.')]);

        return back();
    }

    public function update(TransferRouteRequest $request, Property $property, TransferRoute $transferRoute, SaveTransferRoute $saveRoute): RedirectResponse
    {
        $wasActive = $transferRoute->active;
        $route = $saveRoute($request->user(), $property, $request->routeAttributes(), $transferRoute);
        Inertia::flash('toast', ['type' => 'success', 'message' => $this->savedMessage($route->name, $wasActive, $route->active, __('Route saved.'))]);

        return back();
    }

    private function savedMessage(string $name, bool $wasActive, bool $active, string $default): string
    {
        return match (true) {
            $active && ! $wasActive => __(':name is available.', ['name' => $name]),
            ! $active && $wasActive => __(':name is unavailable.', ['name' => $name]),
            default => $default,
        };
    }
}
