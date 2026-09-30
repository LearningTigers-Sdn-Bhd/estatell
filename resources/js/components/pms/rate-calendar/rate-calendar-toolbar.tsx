import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ListChecks } from 'lucide-react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { Button, buttonVariants } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { calendar } from '@/routes/rates';
import { bulk } from '@/routes/rates/calendar';
import {
    MAX_RANGE_DAYS,
    activePreset,
    presetRange,
    rangeContains,
    shiftRange,
    todayRange,
} from './calendar-model';
import type { CalendarFilters, DateRange, RangePreset } from './calendar-model';
import { RateCalendarLegend } from './rate-calendar-legend';
import { useEffect, useState } from 'react';

type Option = { value: string; label: string };

const PRESETS: { value: string; label: string }[] = [
    { value: '14', label: '14 days' },
    { value: '21', label: '21 days' },
    { value: 'month', label: 'Month' },
];

function toPreset(value: string): RangePreset {
    return value === 'month' ? 'month' : (Number(value) as 14 | 21);
}

/**
 * The top of the rate calendar page: Today, the step arrows, the date range picker, the range presets, the room
 * type and plan filters, and the legend. A change of range asks the server for it, and the neighbouring ranges are
 * prefetched. The filters change the loaded data in the browser.
 */
export function RateCalendarToolbar({
    range,
    today,
    onScrollToToday,
    roomTypeOptions,
    planOptions,
    filters,
    onFiltersChange,
    canManage,
}: {
    range: DateRange;
    today: string;
    onScrollToToday: () => void;
    roomTypeOptions: Option[];
    planOptions: Option[];
    filters: CalendarFilters;
    onFiltersChange: (filters: CalendarFilters) => void;
    /** Whether to show the Bulk edit link. */
    canManage: boolean;
}) {
    const [draft, setDraft] = useState(range);

    useEffect(
        () => setDraft({ from: range.from, to: range.to }),
        [range.from, range.to],
    );

    const visit = (target: DateRange) =>
        router.get(
            calendar.url(undefined, { query: target }),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                only: ['calendar'],
            },
        );
    const link = (target: DateRange) => ({
        href: calendar.url(undefined, { query: target }),
        prefetch: true,
        preserveState: true,
        preserveScroll: true,
        only: ['calendar'],
    });
    const preset = activePreset(range);

    return (
        <div className="flex flex-nowrap items-center gap-2 overflow-x-auto *:shrink-0">
            <Link
                {...link(shiftRange(range, -1))}
                aria-label="Previous range"
                className={buttonVariants({
                    variant: 'outline',
                    size: 'icon',
                })}
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
                className={buttonVariants({
                    variant: 'outline',
                    size: 'icon',
                })}
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
                value={filters.roomTypeId}
                onValueChange={(roomTypeId) =>
                    onFiltersChange({ ...filters, roomTypeId })
                }
            />
            <EnumSelect
                aria-label="Rate plan"
                allLabel="All rate plans"
                className="w-72"
                options={planOptions}
                value={filters.ratePlanId}
                onValueChange={(ratePlanId) =>
                    onFiltersChange({ ...filters, ratePlanId })
                }
            />
            <div className="ml-auto flex items-center gap-2">
                {canManage && (
                    <Link
                        href={bulk.url(undefined, {
                            query: {
                                ...range,
                                ...(filters.ratePlanId
                                    ? { plan: filters.ratePlanId }
                                    : {}),
                            },
                        })}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        <ListChecks data-icon="inline-start" />
                        Bulk edit
                    </Link>
                )}
                <RateCalendarLegend />
            </div>
        </div>
    );
}
