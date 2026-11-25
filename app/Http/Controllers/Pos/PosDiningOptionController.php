<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\SavePosDiningOption;
use App\Data\Pos\PosDiningOptionInputData;
use App\Enums\PosDiningKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\SavePosDiningOptionRequest;
use App\Models\PosDiningOption;
use App\Models\PosOutlet;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosDiningOptionController extends Controller
{
    use RespondsToPos;

    public function index(Request $request, Property $property): Response
    {
        $outlets = PosOutlet::query()->where('property_id', $property->id)->where('native_pos_enabled', true)->orderBy('name')->get(['id', 'name']);
        $outlet = $outlets->firstWhere('id', (int) $request->query('outlet')) ?? $outlets->first();

        return Inertia::render('settings/pos/dining-options', [
            'outlets' => $outlets->map(fn (PosOutlet $row): array => ['value' => (string) $row->id, 'label' => $row->name])->all(),
            'outletId' => $outlet?->id,
            'kinds' => PosDiningKind::options(),
            'options' => $outlet === null ? [] : PosDiningOption::query()->where('pos_outlet_id', $outlet->id)->orderBy('id')->get()->map(fn (PosDiningOption $option): array => [
                'id' => $option->id, 'name' => $option->name, 'kind' => $option->kind->value, 'applyServiceCharge' => $option->apply_service_charge, 'serviceChargeRate' => $option->service_charge_rate,
                'serviceChargeTaxable' => $option->service_charge_taxable, 'packagingFee' => $option->packaging_fee, 'isDefault' => $option->is_default, 'active' => $option->active,
            ])->all(),
        ]);
    }

    public function store(SavePosDiningOptionRequest $request, Property $property, int $outlet, SavePosDiningOption $savePosDiningOption): RedirectResponse
    {
        $this->toast($savePosDiningOption($request->user(), $property, $this->outlet($property, $outlet), $this->input($request))->refusal, __('Dining option added.'));

        return back();
    }

    public function update(SavePosDiningOptionRequest $request, Property $property, int $outlet, int $option, SavePosDiningOption $savePosDiningOption): RedirectResponse
    {
        $model = $this->outlet($property, $outlet);
        $row = PosDiningOption::query()->where('pos_outlet_id', $model->id)->findOrFail($option);
        $this->toast($savePosDiningOption($request->user(), $property, $model, $this->input($request), $row)->refusal, __('Dining option saved.'));

        return back();
    }

    private function outlet(Property $property, int $id): PosOutlet
    {
        return PosOutlet::query()->where('property_id', $property->id)->findOrFail($id);
    }

    private function input(SavePosDiningOptionRequest $request): PosDiningOptionInputData
    {
        return new PosDiningOptionInputData((string) $request->validated('name'), PosDiningKind::from((string) $request->validated('kind')), $request->boolean('apply_service_charge'), (string) $request->validated('service_charge_rate'), $request->boolean('service_charge_taxable'), (string) $request->validated('packaging_fee'), $request->boolean('is_default'), $request->boolean('active'));
    }
}
