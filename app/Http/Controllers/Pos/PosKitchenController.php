<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\BumpKitchenOrder;
use App\Actions\Pos\UpdateKitchenLineStatus;
use App\Enums\PermissionEnum;
use App\Enums\PosKitchenStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\UpdateKitchenLineStatusRequest;
use App\Models\PosKitchenStation;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\KitchenDisplay;
use App\Services\Pos\PosTerminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The kitchen screens: the Web KDS of each station (Kitchen and Window views on one page) and the pickup board of an outlet.
 * Each screen polls a short `version` and reloads its tickets only when it changed. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class PosKitchenController extends Controller
{
    use RespondsToPos;

    public function index(Request $request, Property $property, KitchenDisplay $kitchenDisplay, PosTerminal $terminal): Response
    {
        return $this->display($request, $property, null, $kitchenDisplay, $terminal);
    }

    public function show(Request $request, Property $property, int $station, KitchenDisplay $kitchenDisplay, PosTerminal $terminal): Response
    {
        return $this->display($request, $property, $this->station($property, $station), $kitchenDisplay, $terminal);
    }

    public function version(Property $property, int $station, KitchenDisplay $kitchenDisplay): JsonResponse
    {
        return response()->json(['version' => $kitchenDisplay->version($this->station($property, $station)->id)]);
    }

    public function updateLine(UpdateKitchenLineStatusRequest $request, Property $property, int $line, UpdateKitchenLineStatus $updateLine): RedirectResponse
    {
        $model = PosOrderLine::query()->where('property_id', $property->id)->findOrFail($line);
        $this->toast($updateLine($property, $model, PosKitchenStatus::from((string) $request->validated('status')))->refusal, __('Kitchen line updated.'));

        return back();
    }

    public function bump(Property $property, int $station, int $order, BumpKitchenOrder $bumpKitchenOrder): RedirectResponse
    {
        return $this->bumpOrder($property, $station, $order, false, $bumpKitchenOrder);
    }

    public function recall(Property $property, int $station, int $order, BumpKitchenOrder $bumpKitchenOrder): RedirectResponse
    {
        return $this->bumpOrder($property, $station, $order, true, $bumpKitchenOrder);
    }

    public function pickup(Property $property, int $outlet, KitchenDisplay $kitchenDisplay): Response
    {
        $model = $this->outlet($property, $outlet);

        return Inertia::render('pos/ods', [
            'outlet' => ['id' => $model->id, 'name' => $model->name],
            'version' => $kitchenDisplay->outletVersion($model->id),
            'board' => $kitchenDisplay->pickup($model),
        ]);
    }

    public function pickupVersion(Property $property, int $outlet, KitchenDisplay $kitchenDisplay): JsonResponse
    {
        return response()->json(['version' => $kitchenDisplay->outletVersion($this->outlet($property, $outlet)->id)]);
    }

    private function display(Request $request, Property $property, ?PosKitchenStation $station, KitchenDisplay $kitchenDisplay, PosTerminal $terminal): Response
    {
        $actor = $request->attributes->get('pos_actor');
        $locked = $terminal->isLocked($request, $property);

        $stations = PosKitchenStation::query()
            ->where('property_id', $property->id)
            ->where('active', true)
            ->whereIn('mode', ['kds', 'both'])
            ->with('outlet')
            ->orderBy('pos_outlet_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $station ??= $stations->first();
        $station?->loadMissing('outlet');

        return Inertia::render('pos/kds', [
            'locked' => $locked,
            'canSell' => $actor instanceof User && $actor->checkPermissionTo(PermissionEnum::PosSell->value),
            'canManageMenu' => ! $locked && $actor instanceof User && $actor->checkPermissionTo(PermissionEnum::PosMenu->value),
            'stations' => $stations->map(fn (PosKitchenStation $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'outlet' => $row->outlet->name,
                'warnAfterMinutes' => $row->warn_after_minutes,
                'alertAfterMinutes' => $row->alert_after_minutes,
            ])->values()->all(),
            'station' => $station === null ? null : [
                'id' => $station->id,
                'outletId' => $station->pos_outlet_id,
                'outlet' => $station->outlet->name,
                'name' => $station->name,
                'warnAfterMinutes' => $station->warn_after_minutes,
                'alertAfterMinutes' => $station->alert_after_minutes,
            ],
            'version' => $station === null ? '0' : $kitchenDisplay->version($station->id),
            'tickets' => $station === null ? [] : $kitchenDisplay->tickets($station),
            'recallable' => $station === null ? [] : $kitchenDisplay->recallable($station),
        ]);
    }

    private function bumpOrder(Property $property, int $station, int $order, bool $recall, BumpKitchenOrder $bumpKitchenOrder): RedirectResponse
    {
        $model = PosOrder::query()->where('property_id', $property->id)->findOrFail($order);
        $this->toast($bumpKitchenOrder($property, $this->station($property, $station), $model, $recall)->refusal, $recall ? __('Ticket recalled.') : __('Ticket bumped.'));

        return back();
    }

    private function station(Property $property, int $station): PosKitchenStation
    {
        return PosKitchenStation::query()->where('property_id', $property->id)->where('active', true)->findOrFail($station);
    }

    private function outlet(Property $property, int $outlet): PosOutlet
    {
        return PosOutlet::query()->where('property_id', $property->id)->where('pos_enabled', true)->findOrFail($outlet);
    }
}
