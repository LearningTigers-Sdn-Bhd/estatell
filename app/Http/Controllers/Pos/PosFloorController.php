<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\BatchCreatePosTables;
use App\Actions\Pos\CreatePosTable;
use App\Actions\Pos\SavePosFloor;
use App\Actions\Pos\SavePosTable;
use App\Data\Pos\PosTableInputData;
use App\Enums\PosTableShape;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\BatchCreatePosTablesRequest;
use App\Http\Requests\Pos\SavePosFloorRequest;
use App\Http\Requests\Pos\SavePosTableRequest;
use App\Models\PosFloor;
use App\Models\PosOutlet;
use App\Models\PosTable;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings: the floor plan of an outlet. Floors, batch-created tables, and the place and size of each table, which the
 * editor saves as it is dragged.
 */
class PosFloorController extends Controller
{
    use RespondsToPos;

    public function index(Request $request, Property $property): Response
    {
        $outlets = PosOutlet::query()->where('property_id', $property->id)->where('pos_enabled', true)->orderBy('name')->get();
        $outlet = $outlets->firstWhere('id', (int) $request->query('outlet')) ?? $outlets->first();
        $floors = $outlet === null ? collect() : PosFloor::query()->where('pos_outlet_id', $outlet->id)->orderBy('sort_order')->orderBy('id')->with(['tables' => fn ($query) => $query->orderBy('code')])->get();

        return Inertia::render('settings/pos/floors', [
            'outlets' => $outlets->map(fn (PosOutlet $row): array => ['value' => (string) $row->id, 'label' => $row->name])->all(),
            'outlet' => $outlet === null ? null : ['id' => $outlet->id, 'name' => $outlet->name],
            'floors' => $floors->map(fn (PosFloor $floor): array => [
                'id' => $floor->id,
                'name' => $floor->name,
                'sortOrder' => $floor->sort_order,
                'active' => $floor->active,
                'tables' => $floor->tables->map(fn (PosTable $table): array => [
                    'id' => $table->id, 'code' => $table->code, 'label' => $table->label, 'seats' => $table->seats, 'shape' => $table->shape->value,
                    'x' => $table->pos_x, 'y' => $table->pos_y, 'width' => $table->width, 'height' => $table->height, 'active' => $table->active,
                ])->values()->all(),
            ])->values()->all(),
            'shapes' => PosTableShape::options(),
        ]);
    }

    public function storeFloor(SavePosFloorRequest $request, Property $property, int $outlet, SavePosFloor $savePosFloor): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $this->toast($savePosFloor($request->user(), $property, $model, null, (string) $request->validated('name'), (int) ($request->validated('sort_order') ?? 0), $request->boolean('active', true))->refusal, __('Floor added.'));

        return back();
    }

    public function updateFloor(SavePosFloorRequest $request, Property $property, int $floor, SavePosFloor $savePosFloor): RedirectResponse
    {
        $model = PosFloor::query()->where('property_id', $property->id)->findOrFail($floor);
        $this->toast($savePosFloor($request->user(), $property, $model->outlet, $model, (string) $request->validated('name'), (int) ($request->validated('sort_order') ?? 0), $request->boolean('active', true))->refusal, __('Floor saved.'));

        return back();
    }

    public function batchTables(BatchCreatePosTablesRequest $request, Property $property, int $floor, BatchCreatePosTables $batchCreatePosTables): RedirectResponse
    {
        $model = PosFloor::query()->where('property_id', $property->id)->findOrFail($floor);
        $this->toast($batchCreatePosTables($request->user(), $property, $model, (string) ($request->validated('prefix') ?? ''), (int) $request->validated('start'), (int) $request->validated('end'), (int) $request->validated('seats'))->refusal, __('Tables added.'));

        return back();
    }

    public function storeTable(SavePosTableRequest $request, Property $property, int $floor, CreatePosTable $createPosTable): RedirectResponse
    {
        $model = PosFloor::query()->where('property_id', $property->id)->findOrFail($floor);
        $data = new PosTableInputData(
            (string) $request->validated('code'),
            $request->validated('label'),
            (int) $request->validated('seats'),
            PosTableShape::from((string) $request->validated('shape')),
            (int) $request->validated('x'),
            (int) $request->validated('y'),
            (int) $request->validated('width'),
            (int) $request->validated('height'),
            $request->boolean('active', true),
        );
        $this->toast($createPosTable($request->user(), $property, $model, $data)->refusal, __('Table added.'));

        return back();
    }

    public function updateTable(SavePosTableRequest $request, Property $property, int $table, SavePosTable $savePosTable): RedirectResponse
    {
        $model = PosTable::query()->where('property_id', $property->id)->findOrFail($table);
        $data = new PosTableInputData(
            (string) $request->validated('code'),
            $request->validated('label'),
            (int) $request->validated('seats'),
            PosTableShape::from((string) $request->validated('shape')),
            (int) $request->validated('x'),
            (int) $request->validated('y'),
            (int) $request->validated('width'),
            (int) $request->validated('height'),
            $request->boolean('active', true),
        );
        $this->toast($savePosTable($request->user(), $property, $model, $data)->refusal, __('Table saved.'));

        return back();
    }
}
