<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\SavePosComboSlots;
use App\Actions\Pos\SavePosMenuSchedule;
use App\Data\Pos\PosMenuScheduleInputData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\SavePosComboSlotsRequest;
use App\Http\Requests\Pos\SavePosMenuScheduleRequest;
use App\Models\MealPeriod;
use App\Models\PosCategory;
use App\Models\PosComboSlot;
use App\Models\PosComboSlotOption;
use App\Models\PosItem;
use App\Models\PosItemVariant;
use App\Models\PosMenuSchedule;
use App\Models\PosOutlet;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings: when an outlet sells which categories and items, and the parts of a combo item.
 */
class PosScheduleController extends Controller
{
    use RespondsToPos;

    public function index(Request $request, Property $property): Response
    {
        $outlets = PosOutlet::query()->where('property_id', $property->id)->where('pos_enabled', true)->orderBy('name')->get();
        $outlet = $outlets->firstWhere('id', (int) $request->query('outlet')) ?? $outlets->first();
        $items = PosItem::query()->where('property_id', $property->id)->with(['variants' => fn ($query) => $query->where('active', true)->orderBy('sort_order')->orderBy('id'), 'comboSlots.options'])->orderBy('name')->get();

        return Inertia::render('settings/pos/schedules', [
            'outlets' => $outlets->map(fn (PosOutlet $row): array => ['value' => (string) $row->id, 'label' => $row->name])->all(),
            'outlet' => $outlet === null ? null : ['id' => $outlet->id, 'name' => $outlet->name],
            'schedules' => $outlet === null ? [] : PosMenuSchedule::query()->where('pos_outlet_id', $outlet->id)->with('targets')->orderBy('name')->get()->map(fn (PosMenuSchedule $schedule): array => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'daysOfWeek' => $schedule->days_of_week,
                'startTime' => substr($schedule->start_time, 0, 5),
                'endTime' => substr($schedule->end_time, 0, 5),
                'effectiveFrom' => $schedule->effective_from?->toDateString(),
                'effectiveUntil' => $schedule->effective_until?->toDateString(),
                'mealPeriodId' => $schedule->meal_period_id,
                'active' => $schedule->active,
                'categoryIds' => $schedule->targets->pluck('pos_category_id')->filter()->values()->all(),
                'itemIds' => $schedule->targets->pluck('pos_item_id')->filter()->values()->all(),
            ])->values()->all(),
            'categories' => PosCategory::query()->where('property_id', $property->id)->orderBy('name')->get(['id', 'name'])->map(fn (PosCategory $category): array => ['value' => (string) $category->id, 'label' => $category->name])->all(),
            'items' => $items->map(fn (PosItem $item): array => ['value' => (string) $item->id, 'label' => $item->name])->all(),
            'mealPeriods' => MealPeriod::query()->where('property_id', $property->id)->orderBy('name')->get(['id', 'name'])->map(fn (MealPeriod $period): array => ['value' => (string) $period->id, 'label' => $period->name])->all(),
            'combos' => $items->where('is_combo', true)->map(fn (PosItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'slots' => $item->comboSlots->map(fn (PosComboSlot $slot): array => [
                    'name' => $slot->name,
                    'min' => $slot->min_select,
                    'max' => $slot->max_select,
                    'options' => $slot->options->map(fn (PosComboSlotOption $option): array => ['variantId' => $option->pos_item_variant_id, 'upcharge' => $option->upcharge])->all(),
                ])->all(),
            ])->values()->all(),
            'variants' => $items->flatMap(fn (PosItem $item) => $item->variants->map(fn (PosItemVariant $variant): array => [
                'value' => (string) $variant->id,
                'label' => $item->name.($variant->name === 'Regular' ? '' : ' ('.$variant->name.')'),
                'itemId' => $item->id,
            ]))->values()->all(),
        ]);
    }

    public function storeSchedule(SavePosMenuScheduleRequest $request, Property $property, int $outlet, SavePosMenuSchedule $savePosMenuSchedule): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $this->toast($savePosMenuSchedule($request->user(), $property, $model, null, $this->input($request))->refusal, __('Schedule added.'));

        return back();
    }

    public function updateSchedule(SavePosMenuScheduleRequest $request, Property $property, int $schedule, SavePosMenuSchedule $savePosMenuSchedule): RedirectResponse
    {
        $model = PosMenuSchedule::query()->where('property_id', $property->id)->findOrFail($schedule);
        $this->toast($savePosMenuSchedule($request->user(), $property, $model->outlet, $model, $this->input($request))->refusal, __('Schedule saved.'));

        return back();
    }

    public function saveCombo(SavePosComboSlotsRequest $request, Property $property, int $item, SavePosComboSlots $savePosComboSlots): RedirectResponse
    {
        $model = PosItem::query()->where('property_id', $property->id)->findOrFail($item);
        $slots = array_values(array_map(fn (array $slot): array => [
            'name' => (string) $slot['name'],
            'min' => (int) $slot['min'],
            'max' => (int) $slot['max'],
            'options' => array_values(array_map(fn (array $option): array => ['variantId' => (int) $option['variant_id'], 'upcharge' => (string) $option['upcharge']], $slot['options'])),
        ], $request->validated('slots')));

        $this->toast($savePosComboSlots($request->user(), $property, $model, $slots)->refusal, __('Combo saved.'));

        return back();
    }

    private function input(SavePosMenuScheduleRequest $request): PosMenuScheduleInputData
    {
        return new PosMenuScheduleInputData(
            (string) $request->validated('name'),
            (int) $request->validated('days_of_week'),
            (string) $request->validated('start_time'),
            (string) $request->validated('end_time'),
            array_values(array_map(intval(...), $request->validated('category_ids') ?? [])),
            array_values(array_map(intval(...), $request->validated('item_ids') ?? [])),
            $request->validated('effective_from'),
            $request->validated('effective_until'),
            $request->validated('meal_period_id') === null ? null : (int) $request->validated('meal_period_id'),
            $request->boolean('active'),
        );
    }
}
