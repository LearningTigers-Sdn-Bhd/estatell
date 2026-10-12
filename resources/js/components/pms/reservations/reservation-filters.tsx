import { LayoutGrid, List } from 'lucide-react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { canResetFilters } from './reservation-query';
import type { ReservationFilters, ReservationView } from './reservation-query';

/**
 * Search, source, stay dates, sort and the Cards or List switch. Reset shows only while a filter differs from the default. Search, source and stay run on the server through
 * `onChange`. The view switch makes no request.
 */
export function ReservationFiltersBar({
    filters,
    sort,
    sorts,
    sources,
    view,
    onChange,
    onSortChange,
    onViewChange,
    onReset,
}: {
    filters: ReservationFilters;
    sort: string;
    sorts: EnumOption[];
    sources: EnumOption[];
    view: ReservationView;
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
            <EnumSelect
                options={sources}
                value={filters.source}
                onValueChange={(source) => onChange({ source })}
                allLabel="All sources"
                aria-label="Source"
                className="w-40"
            />
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
                    onChange({ stay: { mode: 'all', from: null, to: null } })
                }
                clearLabel="All dates"
                placeholder="All dates"
                unit="night"
                months={2}
                className="w-auto min-w-52"
            />
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
