import { Link, router } from '@inertiajs/react';
import {
    BedDouble,
    ChevronLeft,
    ChevronRight,
    Layers,
    List,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { Button, buttonVariants } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
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
const WINDOW_ONLY = {
    timeline: ['window', 'rooms', 'stays', 'blocks', 'rates'],
    rooms: ['window', 'rooms', 'stays', 'blocks'],
};

const GROUPING_ICONS: Record<App.Enums.StayViewGrouping, LucideIcon> = {
    none: List,
    room_group: Layers,
    room_type: BedDouble,
};

/**
 * The top of the Timeline View and the Room View: the step arrows, the date range (one date on the Room View), Today,
 * Group by and the legend. A change of window asks the server for it and the neighbouring windows are prefetched.
 * Group by changes the loaded data in the browser. On the Timeline the filters are in the corner of the grid; the Room
 * View adds the Show filter and the Filters button here.
 */
export function StayViewToolbar({
    window,
    today,
    filters,
    onFiltersChange,
    groupingOptions,
    hrefFor,
    onScrollToToday,
    view = 'timeline',
    showOptions,
    filtersButton,
}: {
    window: DateWindow;
    today: string;
    filters: Filters;
    onFiltersChange: (filters: Filters) => void;
    groupingOptions: Option[];
    /** The URL of a window with the current filters. */
    hrefFor: (window: DateWindow) => string;
    onScrollToToday: () => void;
    /** The Room View shows one date, so its window has the same first and last date. */
    view?: 'timeline' | 'rooms';
    /** Room View only: the card states the Show filter offers. */
    showOptions?: Option[];
    /** Room View only: the button that opens the filters. */
    filtersButton?: ReactNode;
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
            {
                preserveState: true,
                preserveScroll: true,
                only: WINDOW_ONLY[view],
            },
        );
    const link = (target: DateWindow) => ({
        href: hrefFor(target),
        prefetch: true,
        preserveState: true,
        preserveScroll: true,
        only: WINDOW_ONLY[view],
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
            {view === 'rooms' ? (
                <DatePicker
                    value={draft.from}
                    className="w-auto"
                    onChange={(date) => {
                        setDraft({ from: date, to: date });

                        if (date !== '') {
                            visit({ from: date, to: date });
                        }
                    }}
                />
            ) : (
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
            )}
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
            {showOptions && (
                <div className="flex items-center gap-2">
                    <Label
                        htmlFor="stay-view-show"
                        className="text-muted-foreground"
                    >
                        Show
                    </Label>
                    <div className="w-64">
                        <MultiCombobox
                            id="stay-view-show"
                            name="show"
                            items={showOptions}
                            value={filters.show}
                            onValueChange={(show) =>
                                onFiltersChange({ ...filters, show })
                            }
                            placeholder="e.g. Arriving"
                            emptyLabel="No matching states."
                            maxChips={2}
                        />
                    </div>
                </div>
            )}
            {filtersButton}
            <div className="flex items-center gap-2">
                <Label className="text-muted-foreground">Group by</Label>
                <ToggleGroup
                    variant="outline"
                    spacing={0}
                    value={[filters.groupBy]}
                    onValueChange={(value) =>
                        value[0] &&
                        onFiltersChange({
                            ...filters,
                            groupBy: value[0] as App.Enums.StayViewGrouping,
                        })
                    }
                    aria-label="Group by"
                >
                    {groupingOptions.map((option) => {
                        const Icon =
                            GROUPING_ICONS[
                                option.value as App.Enums.StayViewGrouping
                            ];

                        return (
                            <ToggleGroupItem
                                key={option.value}
                                value={option.value}
                            >
                                <Icon aria-hidden />
                                {option.label}
                            </ToggleGroupItem>
                        );
                    })}
                </ToggleGroup>
            </div>
            <div className="ml-auto">
                <StatusLegend />
            </div>
        </div>
    );
}
