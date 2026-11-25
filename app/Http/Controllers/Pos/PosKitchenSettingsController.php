<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\PosActionResult;
use App\Actions\Pos\SavePosKitchenRouting;
use App\Actions\Pos\SavePosKitchenStation;
use App\Enums\PosDiningKind;
use App\Enums\PosStationMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\SavePosKitchenRoutingRequest;
use App\Http\Requests\Pos\SavePosKitchenStationRequest;
use App\Models\PosCategory;
use App\Models\PosItem;
use App\Models\PosKitchenStation;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Services\Pos\KitchenDisplay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings: the kitchen stations of an outlet, and which station each category and item is cooked at.
 */
class PosKitchenSettingsController extends Controller
{
    use RespondsToPos;

    public function index(Request $request, Property $property, KitchenDisplay $kitchenDisplay): Response
    {
        $outlets = PosOutlet::query()->where('property_id', $property->id)->where('pos_enabled', true)->orderBy('name')->get();
        $outlet = $outlets->firstWhere('id', (int) $request->query('outlet')) ?? $outlets->first();

        $stations = $outlet === null ? collect() : PosKitchenStation::query()->where('pos_outlet_id', $outlet->id)->orderBy('sort_order')->orderBy('name')->get();

        return Inertia::render('settings/pos/kitchen', [
            'outlets' => $outlets->map(fn (PosOutlet $row): array => ['value' => (string) $row->id, 'label' => $row->name])->all(),
            'outlet' => $outlet === null ? null : ['id' => $outlet->id, 'name' => $outlet->name],
            'stations' => $stations->map(fn (PosKitchenStation $station): array => [
                'id' => $station->id,
                'name' => $station->name,
                'mode' => $station->mode->value,
                'useInternalName' => $station->use_internal_name,
                'warnAfterMinutes' => $station->warn_after_minutes,
                'alertAfterMinutes' => $station->alert_after_minutes,
                'diningKinds' => array_map(fn (PosDiningKind $kind): string => $kind->value, $kitchenDisplay->diningKinds($station) ?? []),
                'sortOrder' => $station->sort_order,
                'active' => $station->active,
            ])->values()->all(),
            'categories' => $outlet === null ? [] : PosCategory::query()->where('property_id', $property->id)->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (PosCategory $category): array => ['id' => $category->id, 'name' => $category->name, 'stationId' => $category->kitchen_station_id])->values()->all(),
            'items' => $outlet === null ? [] : PosItem::query()->where('property_id', $property->id)->with('category:id,name')->orderBy('name')->get()
                ->map(fn (PosItem $item): array => ['id' => $item->id, 'name' => $item->name, 'category' => $item->category?->name, 'stationId' => $item->kitchen_station_id, 'isCombo' => $item->is_combo])->values()->all(),
            'stationModes' => PosStationMode::options(),
            'diningKinds' => PosDiningKind::options(),
        ]);
    }

    public function storeStation(SavePosKitchenStationRequest $request, Property $property, int $outlet, SavePosKitchenStation $savePosKitchenStation): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $this->toast($this->save($request, $property, $model, null, $savePosKitchenStation)->refusal, __('Kitchen station added.'));

        return back();
    }

    public function updateStation(SavePosKitchenStationRequest $request, Property $property, int $station, SavePosKitchenStation $savePosKitchenStation): RedirectResponse
    {
        $model = PosKitchenStation::query()->where('property_id', $property->id)->findOrFail($station);
        $this->toast($this->save($request, $property, $model->outlet, $model, $savePosKitchenStation)->refusal, __('Kitchen station saved.'));

        return back();
    }

    public function updateRouting(SavePosKitchenRoutingRequest $request, Property $property, int $outlet, SavePosKitchenRouting $savePosKitchenRouting): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $this->toast($savePosKitchenRouting($request->user(), $property, $model, (array) $request->validated('categories'), (array) $request->validated('items'))->refusal, __('Kitchen routing saved.'));

        return back();
    }

    private function save(SavePosKitchenStationRequest $request, Property $property, PosOutlet $outlet, ?PosKitchenStation $station, SavePosKitchenStation $savePosKitchenStation): PosActionResult
    {
        return $savePosKitchenStation(
            $request->user(),
            $property,
            $outlet,
            $station,
            (string) $request->validated('name'),
            PosStationMode::from((string) $request->validated('mode')),
            $request->boolean('use_internal_name'),
            (int) $request->validated('warn_after_minutes'),
            (int) $request->validated('alert_after_minutes'),
            $request->validated('filter_dining_kinds') === null ? null : implode(',', (array) $request->validated('filter_dining_kinds')),
            (int) ($request->validated('sort_order') ?? 0),
            $request->boolean('active', true),
        );
    }
}
