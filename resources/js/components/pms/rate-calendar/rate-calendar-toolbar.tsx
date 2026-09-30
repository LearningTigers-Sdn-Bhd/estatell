import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { Button, buttonVariants } from '@/components/ui/button';
import { calendar } from '@/routes/rates';
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

const PRESETS: { value: RangePreset; label: string }[] = [
    { value: 14, label: '14 days' },
    { value: 21, label: '21 days' },
    { value: 'month', label: 'Month' },
];

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
}: {
    range: DateRange;
    today: string;
    onScrollToToday: () => void;
    roomTypeOptions: Option[];
    planOptions: Option[];
    filters: CalendarFilters;
    onFiltersChange: (filters: CalendarFilters) => void;
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
        <div className="flex flex-wrap items-center gap-3">
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
            <div className="flex items-center gap-1">
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
            </div>
            <DateRangePicker
                from={draft.from}
                to={draft.to}
                maxDays={MAX_RANGE_DAYS}
                months={2}
                onChange={(from, to) => {
                    setDraft({ from, to });

                    if (to > from) {
                        visit({ from, to });
                    }
                }}
            />
            <div
                role="group"
                aria-label="Range length"
                className="flex items-center gap-1"
            >
                {PRESETS.map((option) => (
                    <Link
                        key={option.value}
                        {...link(presetRange(option.value, range.from))}
                        aria-pressed={preset === option.value}
                        className={buttonVariants({
                            variant:
                                preset === option.value
                                    ? 'secondary'
                                    : 'outline',
                        })}
                    >
                        {option.label}
                    </Link>
                ))}
            </div>
            <EnumSelect
                aria-label="Room type"
                allLabel="All room types"
                options={roomTypeOptions}
                value={filters.roomTypeId}
                onValueChange={(roomTypeId) =>
                    onFiltersChange({ ...filters, roomTypeId })
                }
            />
            <EnumSelect
                aria-label="Rate plan"
                allLabel="All rate plans"
                options={planOptions}
                value={filters.ratePlanId}
                onValueChange={(ratePlanId) =>
                    onFiltersChange({ ...filters, ratePlanId })
                }
            />
            <div className="ml-auto">
                <RateCalendarLegend />
            </div>
        </div>
    );
}
