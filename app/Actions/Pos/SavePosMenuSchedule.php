<?php

namespace App\Actions\Pos;

use App\Data\Pos\PosMenuScheduleInputData;
use App\Models\MealPeriod;
use App\Models\PosCategory;
use App\Models\PosItem;
use App\Models\PosMenuSchedule;
use App\Models\PosMenuScheduleItem;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a menu schedule of an outlet. It needs a name, at least one day (`schedule_invalid`), a start and an end
 * that differ, written `HH:MM` (an end before the start runs past midnight), and at least one category or item
 * (`schedule_empty`). Dates must run forward. Categories, items and the meal period must be the property's. Its targets are
 * replaced as sent. A refusal writes nothing.
 */
class SavePosMenuSchedule
{
    public function __invoke(User $actor, Property $property, PosOutlet $outlet, ?PosMenuSchedule $schedule, PosMenuScheduleInputData $data): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $outlet, $schedule, $data): PosActionResult {
            if ($outlet->property_id !== $property->id || ($schedule !== null && ($schedule->property_id !== $property->id || $schedule->pos_outlet_id !== $outlet->id))) {
                return PosActionResult::refused('schedule_invalid');
            }

            $categoryIds = array_values(array_unique($data->categoryIds));
            $itemIds = array_values(array_unique($data->itemIds));
            $time = fn (string $value): bool => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;

            if (trim($data->name) === '' || mb_strlen($data->name) > 100 || $data->daysOfWeek < 1 || $data->daysOfWeek > 127 || ! $time($data->startTime) || ! $time($data->endTime) || $data->startTime === $data->endTime
                || ($data->effectiveFrom !== null && $data->effectiveUntil !== null && $data->effectiveUntil < $data->effectiveFrom)) {
                return PosActionResult::refused('schedule_invalid');
            }

            if ($categoryIds === [] && $itemIds === []) {
                return PosActionResult::refused('schedule_empty');
            }

            if (PosCategory::query()->where('property_id', $property->id)->whereIn('id', $categoryIds)->count() !== count($categoryIds)
                || PosItem::query()->where('property_id', $property->id)->whereIn('id', $itemIds)->count() !== count($itemIds)
                || ($data->mealPeriodId !== null && ! MealPeriod::query()->where('property_id', $property->id)->whereKey($data->mealPeriodId)->exists())) {
                return PosActionResult::refused('schedule_invalid');
            }

            $created = $schedule === null;
            $schedule ??= new PosMenuSchedule;
            $schedule->property_id = $property->id;
            $schedule->pos_outlet_id = $outlet->id;
            $schedule->meal_period_id = $data->mealPeriodId;
            $schedule->name = trim($data->name);
            $schedule->days_of_week = $data->daysOfWeek;
            $schedule->start_time = $data->startTime.':00';
            $schedule->end_time = $data->endTime.':00';
            $schedule->effective_from = $data->effectiveFrom === null ? null : CarbonImmutable::parse($data->effectiveFrom);
            $schedule->effective_until = $data->effectiveUntil === null ? null : CarbonImmutable::parse($data->effectiveUntil);
            $schedule->active = $data->active;
            $schedule->save();

            $schedule->targets()->delete();

            foreach ($categoryIds as $id) {
                PosMenuScheduleItem::query()->create(['pos_menu_schedule_id' => $schedule->id, 'pos_category_id' => $id]);
            }

            foreach ($itemIds as $id) {
                PosMenuScheduleItem::query()->create(['pos_menu_schedule_id' => $schedule->id, 'pos_item_id' => $id]);
            }

            activity()->performedOn($schedule)->causedBy($actor)->event($created ? 'pos.menu_schedule_created' : 'pos.menu_schedule_updated')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id])
                ->log($created ? 'POS menu schedule created' : 'POS menu schedule updated');

            return PosActionResult::saved($schedule);
        });
    }
}
