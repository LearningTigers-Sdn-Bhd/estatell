<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\SavePosModifierGroup;
use App\Actions\Pos\ToggleModifierOptionAvailability;
use App\Data\Pos\PosModifierGroupInputData;
use App\Data\Pos\PosModifierOptionInputData;
use App\Enums\PosAvailability;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\SavePosModifierGroupRequest;
use App\Models\PosItem;
use App\Models\PosModifierGroup;
use App\Models\PosModifierOption;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosModifierController extends Controller
{
    use RespondsToPos;

    public function index(Property $property): Response
    {
        return Inertia::render('settings/pos/modifiers', [
            'groups' => PosModifierGroup::query()->where('property_id', $property->id)->with(['options' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'), 'items:id'])->orderBy('sort_order')->orderBy('name')->get()->map(fn (PosModifierGroup $group): array => [
                'id' => $group->id, 'name' => $group->name, 'minSelect' => $group->min_select, 'maxSelect' => $group->max_select, 'maxPerOption' => $group->max_per_option, 'freeIncludedCount' => $group->free_included_count, 'active' => $group->active,
                'itemIds' => $group->items->pluck('id')->all(),
                'options' => $group->options->map(fn (PosModifierOption $option): array => ['id' => $option->id, 'name' => $option->name, 'priceDelta' => $option->price_delta, 'active' => $option->active, 'availability' => $option->availability->value])->all(),
            ])->all(),
            'items' => PosItem::query()->where('property_id', $property->id)->orderBy('name')->get(['id', 'name'])->map(fn (PosItem $item): array => ['value' => (string) $item->id, 'label' => $item->name])->all(),
        ]);
    }

    public function store(SavePosModifierGroupRequest $request, Property $property, SavePosModifierGroup $savePosModifierGroup): RedirectResponse
    {
        $this->toast($savePosModifierGroup($request->user(), $property, $this->input($request))->refusal, __('Modifier group added.'));

        return back();
    }

    public function update(SavePosModifierGroupRequest $request, Property $property, int $group, SavePosModifierGroup $savePosModifierGroup): RedirectResponse
    {
        $model = PosModifierGroup::query()->where('property_id', $property->id)->findOrFail($group);
        $this->toast($savePosModifierGroup($request->user(), $property, $this->input($request), $model)->refusal, __('Modifier group saved.'));

        return back();
    }

    /**
     * The 86 toggle of one addon: sold, POS only, or out.
     */
    public function availability(Request $request, Property $property, int $option, ToggleModifierOptionAvailability $toggle): RedirectResponse
    {
        $model = PosModifierOption::query()->where('property_id', $property->id)->findOrFail($option);
        $value = PosAvailability::tryFrom((string) $request->input('availability'));

        abort_if($value === null, 422);

        $this->toast($toggle($request->user(), $property, $model, $value)->refusal, __('Availability saved.'));

        return back();
    }

    private function input(SavePosModifierGroupRequest $request): PosModifierGroupInputData
    {
        return new PosModifierGroupInputData(
            (string) $request->validated('name'),
            array_values(array_map(fn (array $row): PosModifierOptionInputData => new PosModifierOptionInputData((string) $row['name'], isset($row['id']) ? (int) $row['id'] : null, priceDelta: (string) $row['price_delta'], active: (bool) $row['active']), $request->validated('options'))),
            array_values(array_map(intval(...), $request->validated('item_ids') ?? [])),
            (int) $request->validated('min_select'),
            $request->validated('max_select') === null ? null : (int) $request->validated('max_select'),
            (int) $request->validated('max_per_option'),
            (int) $request->validated('free_included_count'),
            0,
            $request->boolean('active'),
        );
    }
}
