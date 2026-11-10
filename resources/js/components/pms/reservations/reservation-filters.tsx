import { ChevronDown, LayoutGrid, List } from 'lucide-react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { sourceSelectOptions } from './booking-source-options';
import { canResetFilters } from './reservation-query';
import type { ReservationFilters, ReservationView } from './reservation-query';

type StatusOption = App.Data.Reservations.ReservationStatusCountData;

/** The Status button text: Status with nothing ticked, the one ticked status, or "Status · 2". */
function statusLabel(ticked: string[], options: StatusOption[]): string {
    if (ticked.length === 1) {
        return (
            options.find((option) => option.value === ticked[0])?.label ??
            'Status'
        );
    }

    return ticked.length > 1 ? `Status · ${ticked.length}` : 'Status';
}

/** Tick one or more statuses. The menu stays open while ticking, and each tick reloads the list. */
function StatusFilter({
    options,
    value,
    onChange,
}: {
    options: StatusOption[];
    value: string[];
    onChange: (statuses: string[]) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className={buttonVariants({ variant: 'outline' })}
                aria-label="Status"
            >
                {statusLabel(value, options)}
                <ChevronDown data-icon="inline-end" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-48">
                {options.map((option) => (
                    <DropdownMenuCheckboxItem
                        key={option.value}
                        checked={value.includes(option.value)}
                        onCheckedChange={(checked) =>
                            onChange(
                                options
                                    .map((item) => item.value)
                                    .filter((item) =>
                                        item === option.value
                                            ? checked
                                            : value.includes(item),
                                    ),
                            )
                        }
                    >
                        {option.label}
                        <span className="ml-auto text-muted-foreground tabular-nums">
                            {option.count}
                        </span>
                    </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Search, Status, source, stay dates, sort and the Cards or List switch. `statusOptions` null hides Status (every tab
 * but All). `showStay` false hides the stay dates (the Arrivals and Departures tabs use the business date). Reset shows
 * only while a filter differs from the default. Search, Status, source and stay run on the server through `onChange`.
 * The view switch makes no request.
 */
export function ReservationFiltersBar({
    filters,
    sort,
    sorts,
    sources,
    statusOptions,
    view,
    showStay = true,
    onChange,
    onSortChange,
    onViewChange,
    onReset,
}: {
    filters: ReservationFilters;
    sort: string;
    sorts: EnumOption[];
    sources: App.Data.Reservations.BookingSourceOptionData[];
    statusOptions: StatusOption[] | null;
    view: ReservationView;
    showStay?: boolean;
    onChange: (changes: Partial<ReservationFilters>) => void;
    onSortChange: (sort: string) => void;
    onViewChange: (view: ReservationView) => void;
    onReset: () => void;
}) {
    return (
        <FilterBar
            search={filters.search}
            onSearchChange={(search) => onChange({ search })}
            searchPlaceholder="Search reservations by ref, guest, phone or email"
        >
            {statusOptions !== null && (
                <StatusFilter
                    options={statusOptions}
                    value={filters.statuses}
                    onChange={(statuses) => onChange({ statuses })}
                />
            )}
            <EnumSelect
                options={sourceSelectOptions(sources)}
                value={filters.source}
                onValueChange={(source) => onChange({ source })}
                allLabel="All sources"
                aria-label="Source"
                className="w-40"
            />
            {showStay && (
                <DateRangePicker
                    openEnded
                    from={filters.stay.from ?? ''}
                    to={filters.stay.to ?? ''}
                    onChange={(from, to) =>
                        onChange({
                            stay: { mode: 'custom', from, to: to || null },
                        })
                    }
                    onClear={() =>
                        onChange({
                            stay: { mode: 'all', from: null, to: null },
                        })
                    }
                    clearLabel="All dates"
                    placeholder="All dates"
                    unit="night"
                    months={2}
                    className="w-auto min-w-52"
                />
            )}
            {canResetFilters(filters) && (
                <Button type="button" variant="ghost" onClick={onReset}>
                    Reset
                </Button>
            )}
            <EnumSelect
                options={sorts}
                value={sort}
                onValueChange={onSortChange}
                aria-label="Sort"
                className={cn('w-52', view === 'list' && 'lg:hidden')}
            />
            <ToggleGroup
                variant="outline"
                spacing={0}
                aria-label="View"
                value={[view]}
                onValueChange={(values) => {
                    const next = values[0];

                    if (next === 'cards' || next === 'list') {
                        onViewChange(next);
                    }
                }}
            >
                <ToggleGroupItem value="cards" aria-label="Cards">
                    <LayoutGrid />
                    Cards
                </ToggleGroupItem>
                <ToggleGroupItem value="list" aria-label="List">
                    <List />
                    List
                </ToggleGroupItem>
            </ToggleGroup>
        </FilterBar>
    );
}
