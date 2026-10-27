import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, PencilLine } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { Button, buttonVariants } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { edit as editPricing } from '@/routes/settings/rate-plans/pricing';
import { show } from '@/routes/settings/rate-plans/saved-prices';
import {
    MAX_RANGE_DAYS,
    activePreset,
    presetRange,
    rangeContains,
    shiftRange,
    todayRange,
} from './calendar-model';
import type { DateRange, RangePreset } from './calendar-model';
import { RateCalendarLegend } from './rate-calendar-legend';

const PRESETS: { value: string; label: string }[] = [
    { value: '14', label: '14 days' },
    { value: '21', label: '21 days' },
    { value: 'month', label: 'Month' },
];

function toPreset(value: string): RangePreset {
    return value === 'month' ? 'month' : (Number(value) as 14 | 21);
}

/**
 * The top of the Saved prices tab: Today, the step arrows, the date range picker, the range presets, the room
 * type filter, the legend and a link to the Pricing rules tab. A change of range asks the server for it.
 */
export function SavedPricesToolbar({
    ratePlanId,
    range,
    today,
    onScrollToToday,
    roomTypeOptions,
    roomTypeId,
    onRoomTypeChange,
}: {
    ratePlanId: number;
    range: DateRange;
    today: string;
    onScrollToToday: () => void;
    roomTypeOptions: { value: string; label: string }[];
    roomTypeId: string;
    onRoomTypeChange: (roomTypeId: string) => void;
}) {
    const [draft, setDraft] = useState(range);

    useEffect(
        () => setDraft({ from: range.from, to: range.to }),
        [range.from, range.to],
    );

    const url = (target: DateRange) =>
        show.url({ ratePlan: ratePlanId }, { query: target });
    const visit = (target: DateRange) =>
        router.get(
            url(target),
            {},
            { preserveState: true, preserveScroll: true, only: ['saved'] },
        );
    const link = (target: DateRange) => ({
        href: url(target),
        prefetch: true,
        preserveState: true,
        preserveScroll: true,
        only: ['saved'],
    });
    const preset = activePreset(range);

    return (
        <div className="flex flex-nowrap items-center gap-2 overflow-x-auto *:shrink-0">
            <Link
                {...link(shiftRange(range, -1))}
                aria-label="Previous range"
                className={buttonVariants({ variant: 'outline', size: 'icon' })}
            >
                <ChevronLeft />
            </Link>
            <DateRangePicker
                from={draft.from}
                to={draft.to}
                maxDays={MAX_RANGE_DAYS}
                className="w-auto"
                months={2}
                onChange={(from, to) => {
                    setDraft({ from, to });

                    if (to > from) {
                        visit({ from, to });
                    }
                }}
            />
            <Link
                {...link(shiftRange(range, 1))}
                aria-label="Next range"
                className={buttonVariants({ variant: 'outline', size: 'icon' })}
            >
                <ChevronRight />
            </Link>
            <Button
                type="button"
                variant="outline"
                onClick={() =>
                    rangeContains(range, today)
                        ? onScrollToToday()
                        : visit(todayRange(today, range))
                }
            >
                Today
            </Button>
            <ToggleGroup
                variant="outline"
                spacing={0}
                aria-label="Range length"
                value={preset === null ? [] : [String(preset)]}
                onValueChange={(values) => {
                    if (values.length > 0) {
                        visit(presetRange(toPreset(values[0]), range.from));
                    }
                }}
            >
                {PRESETS.map((option) => (
                    <ToggleGroupItem key={option.value} value={option.value}>
                        {option.label}
                    </ToggleGroupItem>
                ))}
            </ToggleGroup>
            <EnumSelect
                aria-label="Room type"
                allLabel="All room types"
                className="w-56"
                options={roomTypeOptions}
                value={roomTypeId}
                onValueChange={onRoomTypeChange}
            />
            <div className="ml-auto flex items-center gap-2">
                <RateCalendarLegend />
                <Link
                    href={editPricing.url({ ratePlan: ratePlanId })}
                    className={buttonVariants({ variant: 'outline' })}
                >
                    <PencilLine data-icon="inline-start" />
                    Edit in Pricing rules
                </Link>
            </div>
        </div>
    );
}
