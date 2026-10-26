import { ListFilter, Search } from 'lucide-react';
import { EnumSelect } from '@/components/pms/enum-select';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldLabel,
    FieldTitle,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';

type Filters = App.Data.StayView.StayViewFiltersData;
type Option = { value: string; label: string };

/** Distinct options with a count of the rooms that use each. */
export function countedOptions(
    rooms: App.Data.StayView.RoomRowData[],
    pick: (room: App.Data.StayView.RoomRowData) => {
        id: number | null;
        name: string | null;
    },
): (Option & { description: string })[] {
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
        description: `${entry.count} ${entry.count === 1 ? 'room' : 'rooms'}`,
    }));
}

/**
 * How many filters are set: each picked room group, room type and status, and one for a search with text. Group by and
 * the rate settings change how the grid looks, not which rooms show, so they are not counted.
 */
export function activeFilterCount(filters: Filters): number {
    return (
        filters.roomGroupIds.length +
        filters.roomTypeIds.length +
        filters.statuses.length +
        (filters.search.trim() === '' ? 0 : 1)
    );
}

/** The filters that narrow the rooms, set to show everything. Group by is kept. */
export function clearedFilters(filters: Filters): Filters {
    return {
        ...filters,
        roomGroupIds: [],
        roomTypeIds: [],
        statuses: [],
        search: '',
    };
}

/**
 * A button that opens the filters: search, room group, room type, status and, on the Timeline, the rate settings.
 * The Timeline puts it in the corner of the grid; the Room View passes no rate settings and a plain `className`.
 * Changes apply as they are made, since the filters only change the loaded data in the browser.
 */
export function StayViewFilters({
    rooms,
    filters,
    onFiltersChange,
    statusOptions,
    showRates = false,
    onShowRatesChange,
    planOptions = [],
    ratePlanId = '',
    onRatePlanChange,
    className = 'w-full justify-start bg-grid-header text-grid-header-foreground hover:bg-grid-hover',
    description = 'Choose which rooms and stays show on the timeline.',
}: {
    rooms: App.Data.StayView.RoomRowData[];
    filters: Filters;
    onFiltersChange: (filters: Filters) => void;
    statusOptions: Option[];
    /** The rate settings. Left out, the sheet has none (the Room View has no rate row). */
    showRates?: boolean;
    onShowRatesChange?: (show: boolean) => void;
    /** The rate plans the prices can show, and the one chosen. */
    planOptions?: Option[];
    ratePlanId?: string;
    onRatePlanChange?: (ratePlanId: number) => void;
    /** The classes of the trigger button. */
    className?: string;
    description?: string;
}) {
    const count = activeFilterCount(filters);

    return (
        <Sheet>
            <SheetTrigger
                render={
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className={className}
                    />
                }
            >
                <ListFilter aria-hidden />
                Filters
                {count > 0 && <Badge variant="secondary">{count}</Badge>}
            </SheetTrigger>
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>Filters</SheetTitle>
                    <SheetDescription>{description}</SheetDescription>
                </SheetHeader>
                <div className="flex flex-1 flex-col gap-4 overflow-y-auto px-4">
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="stay-view-search">Search</Label>
                        <div className="relative">
                            <Search
                                aria-hidden
                                className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                id="stay-view-search"
                                type="search"
                                placeholder="e.g. Ahmad or 201"
                                className="pl-8"
                                value={filters.search}
                                onChange={(event) =>
                                    onFiltersChange({
                                        ...filters,
                                        search: event.target.value,
                                    })
                                }
                            />
                        </div>
                    </div>
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="stay-view-room-group">Room group</Label>
                        <MultiCombobox
                            id="stay-view-room-group"
                            name="room_group_ids"
                            items={countedOptions(rooms, (room) => ({
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
                            placeholder="e.g. Left wing"
                            emptyLabel="No matching room groups."
                        />
                    </div>
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="stay-view-room-type">Room type</Label>
                        <MultiCombobox
                            id="stay-view-room-type"
                            name="room_type_ids"
                            items={countedOptions(rooms, (room) => ({
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
                            placeholder="e.g. Deluxe"
                            emptyLabel="No matching room types."
                        />
                    </div>
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="stay-view-status">Status</Label>
                        <MultiCombobox
                            id="stay-view-status"
                            name="statuses"
                            items={statusOptions}
                            value={filters.statuses}
                            onValueChange={(statuses) =>
                                onFiltersChange({ ...filters, statuses })
                            }
                            placeholder="e.g. Dirty"
                            emptyLabel="No matching statuses."
                        />
                    </div>
                    {onShowRatesChange && (
                        <FieldLabel htmlFor="stay-view-show-rates">
                            <Field orientation="horizontal">
                                <Switch
                                    id="stay-view-show-rates"
                                    checked={showRates}
                                    onCheckedChange={onShowRatesChange}
                                />
                                <FieldContent>
                                    <FieldTitle>Show rates</FieldTitle>
                                    <FieldDescription>
                                        Show the nightly price for each room
                                        type under the dates.
                                    </FieldDescription>
                                </FieldContent>
                            </Field>
                        </FieldLabel>
                    )}
                    {onShowRatesChange &&
                        onRatePlanChange &&
                        showRates &&
                        planOptions.length > 0 && (
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="stay-view-rate-plan">
                                    Rate plan
                                </Label>
                                <EnumSelect
                                    id="stay-view-rate-plan"
                                    className="w-full"
                                    options={planOptions}
                                    value={ratePlanId}
                                    onValueChange={(value) =>
                                        onRatePlanChange(Number(value))
                                    }
                                />
                            </div>
                        )}
                </div>
                <SheetFooter className="border-t bg-muted/50">
                    <SheetClose
                        render={<Button type="button" className="w-full" />}
                    >
                        Done
                    </SheetClose>
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full"
                        disabled={count === 0}
                        onClick={() => onFiltersChange(clearedFilters(filters))}
                    >
                        Clear filters
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
