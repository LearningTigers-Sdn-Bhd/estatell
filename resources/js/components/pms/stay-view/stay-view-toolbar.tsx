import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { MultiFilter } from './stay-view-filters';
import type { FilterOption } from './stay-view-filters';
import { StatusLegend } from './status-legend';
import {
    MAX_WINDOW_DAYS,
    shiftWindow,
    windowContains,
    windowFromToday,
} from './stay-view-url';
import type { DateWindow } from './stay-view-url';

type Filters = App.Data.StayView.StayViewFiltersData;
type Option = { value: string; label: string };

/** The props a visit to another window needs, so the page keeps its state and reloads only the window's data. */
const WINDOW_ONLY = ['window', 'rooms', 'stays', 'blocks', 'rates'];

/** Distinct options with a count of the rooms that use each. */
export function countedOptions(
    rooms: App.Data.StayView.RoomRowData[],
    pick: (room: App.Data.StayView.RoomRowData) => {
        id: number | null;
        name: string | null;
    },
): FilterOption[] {
    const counts = new Map<number, { label: string; count: number }>();

    for (const room of rooms) {
        const { id, name } = pick(room);

        if (id === null || name === null) {
            continue;
        }

        const entry = counts.get(id) ?? { label: name, count: 0 };

        entry.count += 1;
        counts.set(id, entry);
    }

    return [...counts].map(([id, entry]) => ({
        value: String(id),
        label: entry.label,
        count: entry.count,
    }));
}

/**
 * The top of the Timeline View: the step arrows, the date range, Today, the filters, Group by, the rates switch and plan,
 * search and the legend. A change of window asks the server for it and the neighbouring windows are prefetched. The
 * filters, Group by and search change the loaded data in the browser.
 */
export function StayViewToolbar({
    window,
    today,
    rooms,
    filters,
    onFiltersChange,
    groupingOptions,
    statusOptions,
    showRates,
    onShowRatesChange,
    planOptions,
    ratePlanId,
    onRatePlanChange,
    hrefFor,
    onScrollToToday,
}: {
    window: DateWindow;
    today: string;
    rooms: App.Data.StayView.RoomRowData[];
    filters: Filters;
    onFiltersChange: (filters: Filters) => void;
    groupingOptions: Option[];
    statusOptions: Option[];
    showRates: boolean;
    onShowRatesChange: (show: boolean) => void;
    /** The rate plans the prices can show, and the one chosen. */
    planOptions: Option[];
    ratePlanId: string;
    onRatePlanChange: (ratePlanId: number) => void;
    /** The URL of a window with the current filters. */
    hrefFor: (window: DateWindow) => string;
    onScrollToToday: () => void;
}) {
    const [draft, setDraft] = useState(window);

    useEffect(
        () => setDraft({ from: window.from, to: window.to }),
        [window.from, window.to],
    );

    const visit = (target: DateWindow) =>
        router.get(
            hrefFor(target),
            {},
            { preserveState: true, preserveScroll: true, only: WINDOW_ONLY },
        );
    const link = (target: DateWindow) => ({
        href: hrefFor(target),
        prefetch: true,
        preserveState: true,
        preserveScroll: true,
        only: WINDOW_ONLY,
    });

    return (
        <div className="flex flex-nowrap items-center gap-2 overflow-x-auto *:shrink-0">
            <Link
                {...link(shiftWindow(window, -1))}
                aria-label="Previous dates"
                className={buttonVariants({ variant: 'outline', size: 'icon' })}
            >
                <ChevronLeft />
            </Link>
            <DateRangePicker
                from={draft.from}
                to={draft.to}
                maxDays={MAX_WINDOW_DAYS}
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
                {...link(shiftWindow(window, 1))}
                aria-label="Next dates"
                className={buttonVariants({ variant: 'outline', size: 'icon' })}
            >
                <ChevronRight />
            </Link>
            <Button
                type="button"
                variant="outline"
                onClick={() =>
                    windowContains(window, today)
                        ? onScrollToToday()
                        : visit(windowFromToday(today, window))
                }
            >
                Today
            </Button>
            <MultiFilter
                label="Room group"
                options={countedOptions(rooms, (room) => ({
                    id: room.roomGroupId,
                    name: room.roomGroupName,
                }))}
                value={filters.roomGroupIds.map(String)}
                onValueChange={(value) =>
                    onFiltersChange({
                        ...filters,
                        roomGroupIds: value.map(Number),
                    })
                }
            />
            <MultiFilter
                label="Room type"
                options={countedOptions(rooms, (room) => ({
                    id: room.roomTypeId,
                    name: room.roomTypeName,
                }))}
                value={filters.roomTypeIds.map(String)}
                onValueChange={(value) =>
                    onFiltersChange({
                        ...filters,
                        roomTypeIds: value.map(Number),
                    })
                }
            />
            <MultiFilter
                label="Status"
                options={statusOptions}
                value={filters.statuses}
                onValueChange={(statuses) =>
                    onFiltersChange({ ...filters, statuses })
                }
            />
            <div className="flex items-center gap-2">
                <Label
                    htmlFor="stay-view-group-by"
                    className="text-muted-foreground"
                >
                    Group by
                </Label>
                <EnumSelect
                    id="stay-view-group-by"
                    className="w-36"
                    options={groupingOptions}
                    value={filters.groupBy}
                    onValueChange={(value) =>
                        onFiltersChange({
                            ...filters,
                            groupBy: value as App.Enums.StayViewGrouping,
                        })
                    }
                />
            </div>
            <div className="flex items-center gap-2">
                <Switch
                    id="stay-view-show-rates"
                    checked={showRates}
                    onCheckedChange={onShowRatesChange}
                />
                <Label htmlFor="stay-view-show-rates">Show rates</Label>
            </div>
            {showRates && planOptions.length > 0 && (
                <EnumSelect
                    aria-label="Rate plan for the prices"
                    className="w-36"
                    options={planOptions}
                    value={ratePlanId}
                    onValueChange={(value) => onRatePlanChange(Number(value))}
                />
            )}
            <div className="relative">
                <Search
                    aria-hidden
                    className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    type="search"
                    aria-label="Search guests, references or rooms"
                    placeholder="e.g. Ahmad or 201"
                    className="w-48 pl-8"
                    value={filters.search}
                    onChange={(event) =>
                        onFiltersChange({
                            ...filters,
                            search: event.target.value,
                        })
                    }
                />
            </div>
            <StatusLegend />
        </div>
    );
}
