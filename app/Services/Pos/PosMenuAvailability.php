<?php

namespace App\Services\Pos;

use App\Models\PosItem;
use App\Models\PosMenuSchedule;
use App\Models\PosOutlet;
use Carbon\CarbonInterface;
use Closure;

/**
 * Whether an item may be sold at an outlet right now, by the outlet's menu schedules. An item or category that no active
 * schedule names is always on sale. One that a schedule names is on sale only while one of those schedules is open: today is
 * one of its days, the time is inside its window (a window that ends before it starts runs past midnight and belongs to the
 * day it started), and today is inside its effective dates. It reads only.
 */
class PosMenuAvailability
{
    /**
     * @return Closure(PosItem): bool
     */
    public function checker(PosOutlet $outlet, ?CarbonInterface $now = null): Closure
    {
        $now ??= now();
        $named = ['item' => [], 'category' => []];
        $open = ['item' => [], 'category' => []];

        $schedules = PosMenuSchedule::query()->where('pos_outlet_id', $outlet->id)->where('active', true)->with('targets')->get();

        foreach ($schedules as $schedule) {
            $isOpen = $this->isOpen($schedule, $now);

            foreach ($schedule->targets as $target) {
                $kind = $target->pos_item_id !== null ? 'item' : 'category';
                $id = $target->pos_item_id ?? $target->pos_category_id;
                $named[$kind][$id] = true;

                if ($isOpen) {
                    $open[$kind][$id] = true;
                }
            }
        }

        return fn (PosItem $item): bool => (! isset($named['item'][$item->id]) && ! isset($named['category'][$item->pos_category_id]))
            || isset($open['item'][$item->id])
            || isset($open['category'][$item->pos_category_id]);
    }

    public function sellable(PosOutlet $outlet, PosItem $item, ?CarbonInterface $now = null): bool
    {
        return ($this->checker($outlet, $now))($item);
    }

    private function isOpen(PosMenuSchedule $schedule, CarbonInterface $now): bool
    {
        if (($schedule->effective_from !== null && $now->toDateString() < $schedule->effective_from->toDateString())
            || ($schedule->effective_until !== null && $now->toDateString() > $schedule->effective_until->toDateString())) {
            return false;
        }

        $time = $now->format('H:i:s');
        $start = substr($schedule->start_time, 0, 8);
        $end = substr($schedule->end_time, 0, 8);
        $today = 1 << ($now->dayOfWeekIso - 1);
        $yesterday = 1 << (($now->dayOfWeekIso + 5) % 7);

        if ($start < $end) {
            return ($schedule->days_of_week & $today) !== 0 && $time >= $start && $time < $end;
        }

        return (($schedule->days_of_week & $today) !== 0 && $time >= $start) || (($schedule->days_of_week & $yesterday) !== 0 && $time < $end);
    }
}
