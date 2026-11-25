<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\SavePosOutlet;
use App\Data\Pos\PosOutletData;
use App\Data\Pos\PosOutletRowData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\SavePosOutletRequest;
use App\Models\ChargeItem;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Services\Pos\PosRefusalMessages;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PosOutletController extends Controller
{
    public function index(Property $property): Response
    {
        return Inertia::render('settings/pos-outlets/index', [
            'outlets' => PosOutlet::query()->where('property_id', $property->id)->with('defaultChargeItem')->orderBy('name')->get()->map(PosOutletRowData::fromModel(...))->values(),
            'chargeItems' => ChargeItem::query()->where('property_id', $property->id)->active()->ordered()->get(['id', 'name'])->map(fn (ChargeItem $item): array => ['value' => (string) $item->id, 'label' => $item->name])->values(),
        ]);
    }

    public function store(SavePosOutletRequest $request, Property $property, SavePosOutlet $savePosOutlet): RedirectResponse
    {
        return $this->save($request, $property, $savePosOutlet, null, __('Outlet added.'));
    }

    public function update(SavePosOutletRequest $request, Property $property, int $outlet, SavePosOutlet $savePosOutlet): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);

        return $this->save($request, $property, $savePosOutlet, $model, __('Outlet saved.'));
    }

    private function save(SavePosOutletRequest $request, Property $property, SavePosOutlet $savePosOutlet, ?PosOutlet $outlet, string $success): RedirectResponse
    {
        $chargeItemId = $request->validated('default_charge_item_id');
        $posEnabled = $request->has('pos_enabled')
            ? $request->boolean('pos_enabled')
            : ($outlet !== null && $outlet->pos_enabled);

        $result = $savePosOutlet($request->user(), $property, new PosOutletData(
            (string) $request->validated('name'),
            (string) $request->validated('code'),
            $chargeItemId === null ? null : (int) $chargeItemId,
            $request->boolean('active'),
            $posEnabled,
        ), $outlet);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => PosRefusalMessages::message((string) $result->refusal)]
            : ['type' => 'success', 'message' => $success]);

        return to_route('settings.pos-outlets.index', $property);
    }
}
