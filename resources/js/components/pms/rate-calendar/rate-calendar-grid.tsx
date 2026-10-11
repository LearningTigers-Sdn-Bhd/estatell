import { Ban, ChevronDown, ChevronRight, Link2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { Ref } from 'react';
import { buildDateColumns } from '@/components/pms/grid/date-columns';
import { DateGrid } from '@/components/pms/grid/date-grid';
import type { DateGridHandle } from '@/components/pms/grid/date-grid';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
import { InclusionsPopover } from './inclusions-popover';
import { RateCellSheet } from './rate-cell-sheet';
import type { EditingCell } from './rate-cell-sheet';
import {
    buildRows,
    cellFlags,
    formatAmount,
    indexAvailability,
    indexCells,
    nightsLabel,
    occupancyOn,
    planNote,
    stayLimit,
    toggleCollapsed,
} from './calendar-model';
import type { CalendarFilters, CalendarRow } from './calendar-model';

type Cell = App.Data.Rates.RateCalendarCellData;
type Availability = App.Data.Rates.RateCalendarAvailabilityData;

/** A small bar with a track that shows on the grid's muted backgrounds, where the default muted track vanishes. */
const BAR_CLASS =
    'w-10 gap-0 [&_[data-slot=progress-track]]:h-1.5 [&_[data-slot=progress-track]]:bg-foreground/15';

const FLAG_LABELS = {
    'stop-sell': 'Stop sell',
    'closed-arrival': 'Closed to arrival',
    'closed-departure': 'Closed to departure',
} as const;

export function cellTitle(
    cell: Cell,
    derived: boolean,
    stayNights: number | null = null,
): string {
    const hours = cell.hours ?? [];
    const parts = [
        hours.length > 0
            ? `from ${formatAmount(cell.amount)} (${hours.map((price) => `${price.hours}h ${formatAmount(price.amount)}`).join(', ')})`
            : cell.amount === null
              ? null
              : `${formatAmount(cell.amount)}${derived ? ' (follows parent)' : ''}${stayNights === null ? '' : ` for ${nightsLabel(stayNights)}`}`,
        cell.arrivalNotAllowed ? 'No arrival on this day' : null,
        ...cellFlags(cell).map((flag) => FLAG_LABELS[flag]),
        stayLimit(cell),
    ];

    return parts.filter(Boolean).join(', ');
}

function RateCell({
    cell,
    derived,
    stayNights,
}: {
    cell: Cell | undefined;
    derived: boolean;
    /** The fixed length of a package stay. The price on a package cell is for the whole stay. */
    stayNights: number | null;
}) {
    if (!cell) {
        return null;
    }

    const flags = cellFlags(cell);
    const limit = stayLimit(cell);

    return (
        <div
            title={cellTitle(cell, derived, stayNights)}
            className={cn(
                'flex h-full flex-col items-center justify-center gap-0.5 text-xs tabular-nums',
                cell.stopSell && 'bg-destructive/10',
            )}
        >
            <span className="sr-only">
                {cellTitle(cell, derived, stayNights)}
            </span>
            {cell.amount !== null && (
                <span
                    aria-hidden
                    className={cn(
                        'flex items-center gap-1 text-sm font-medium',
                        (cell.stopSell || cell.arrivalNotAllowed) &&
                            'text-grid-muted-foreground',
                        cell.stopSell && 'line-through',
                        derived && 'italic',
                    )}
                >
                    {derived && <Link2 className="size-3 text-info" />}
                    {(cell.hours ?? []).length > 0 && (
                        <span className="text-xs font-normal text-grid-muted-foreground">
                            from
                        </span>
                    )}
                    {formatAmount(cell.amount)}
                    {stayNights !== null && (
                        <span className="text-xs font-normal text-grid-muted-foreground">
                            /{stayNights}N
                        </span>
                    )}
                </span>
            )}
            {(flags.length > 0 || limit || cell.arrivalNotAllowed) && (
                <span
                    aria-hidden
                    className="flex items-center gap-1 text-grid-muted-foreground"
                >
                    {cell.stopSell && (
                        <Ban className="size-3 text-destructive" />
                    )}
                    {cell.closedToArrival && (
                        <span className="rounded-sm bg-warning px-1 text-warning-foreground">
                            CTA
                        </span>
                    )}
                    {cell.closedToDeparture && (
                        <span className="rounded-sm bg-warning px-1 text-warning-foreground">
                            CTD
                        </span>
                    )}
                    {cell.arrivalNotAllowed && <span>No arrival</span>}
                    {limit && <span>{limit}</span>}
                </span>
            )}
        </div>
    );
}

/**
 * The units of a room type on a date: how many are open and how many are sold. A date with nothing open shows in
 * the warning tone.
 */
function AvailabilityCell({ night }: { night: Availability | undefined }) {
    if (!night) {
        return null;
    }

    return (
        <div
            title={`${night.open} open, ${night.sold} sold, ${night.units} units`}
            className="flex h-full flex-col items-center justify-center gap-0.5 text-xs tabular-nums"
        >
            <Progress
                value={night.units === 0 ? 0 : (night.sold / night.units) * 100}
                aria-label={`${night.sold} of ${night.units} units sold`}
                className={BAR_CLASS}
            />
            <span
                className={cn(
                    'font-medium',
                    night.open === 0 ? 'text-warning' : 'text-foreground',
                )}
            >
                {night.open} open
            </span>
            <span className="text-grid-muted-foreground">
                {night.sold} sold
            </span>
        </div>
    );
}

/**
 * A range of daily rates: a heading for each room type and a row for each plan that sells it. A plan that
 * follows another shows the calculated price with a link marker.
 */
export function RateCalendarGrid({
    gridRef,
    roomTypes,
    dates,
    today,
    weekendDays,
    filters,
    canManage,
    canManageInclusions,
    currency,
}: {
    gridRef: Ref<DateGridHandle>;
    roomTypes: App.Data.Rates.RateCalendarRoomTypeData[];
    dates: string[];
    today: string;
    /** ISO weekdays whose nights are weekend nights, from the property settings. */
    weekendDays: number[];
    filters: CalendarFilters;
    /** Whether a click on a cell opens the date editor. */
    canManage: boolean;
    /** Whether the inclusions popover links to the plan's Inclusions tab. */
    canManageInclusions: boolean;
    currency: string;
}) {
    const columns = useMemo(
        () => buildDateColumns(dates, today, weekendDays),
        [dates, today, weekendDays],
    );
    const [collapsedIds, setCollapsedIds] = useState<ReadonlySet<number>>(
        new Set(),
    );
    const [editing, setEditing] = useState<EditingCell | null>(null);
    const rows = useMemo(
        () => buildRows(roomTypes, filters, collapsedIds),
        [roomTypes, filters, collapsedIds],
    );
    const shownRoomTypes = useMemo(
        () =>
            filters.roomTypeId === ''
                ? roomTypes
                : roomTypes.filter(
                      (roomType) => String(roomType.id) === filters.roomTypeId,
                  ),
        [roomTypes, filters.roomTypeId],
    );
    const availabilityByType = useMemo(
        () =>
            new Map(
                roomTypes.map((roomType) => [
                    roomType.id,
                    indexAvailability(roomType),
                ]),
            ),
        [roomTypes],
    );
    const cellsByPlan = useMemo(() => {
        const index = new Map<string, Map<string, Cell>>();

        roomTypes.forEach((roomType) =>
            roomType.plans.forEach((plan) =>
                index.set(`${roomType.id}-${plan.id}`, indexCells(plan)),
            ),
        );

        return index;
    }, [roomTypes]);

    return (
        <>
            <DateGrid<CalendarRow>
                ref={gridRef}
                aria-label="Rate calendar"
                className="rounded-none border-x-0"
                labelWidth={288}
                fill
                groupHeight={56}
                rows={rows}
                columns={columns}
                renderLabel={(row) =>
                    row.kind === 'group' ? (
                        <button
                            type="button"
                            aria-expanded={!row.collapsed}
                            title={row.collapsed ? 'Expand' : 'Collapse'}
                            className="flex w-full min-w-0 items-center gap-2 rounded-md text-left outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                            onClick={() =>
                                setCollapsedIds((current) =>
                                    toggleCollapsed(current, row.roomType.id),
                                )
                            }
                        >
                            {row.collapsed ? (
                                <ChevronRight
                                    aria-hidden
                                    className="size-4 shrink-0"
                                />
                            ) : (
                                <ChevronDown
                                    aria-hidden
                                    className="size-4 shrink-0"
                                />
                            )}
                            <span className="truncate text-sm font-semibold">
                                {row.roomType.name}
                            </span>
                            {row.collapsed && (
                                <span className="ml-auto shrink-0 text-xs text-grid-muted-foreground">
                                    {row.planCount}{' '}
                                    {row.planCount === 1 ? 'plan' : 'plans'}
                                </span>
                            )}
                        </button>
                    ) : (
                        <span className="flex min-w-0 flex-col">
                            <span className="flex min-w-0 items-center gap-1">
                                <span className="truncate text-sm">
                                    {row.plan.name}
                                </span>
                                <InclusionsPopover
                                    plan={row.plan}
                                    canManage={canManageInclusions}
                                />
                            </span>
                            {planNote(row.plan) && (
                                <span
                                    title={planNote(row.plan) ?? undefined}
                                    className="truncate text-xs text-grid-muted-foreground"
                                >
                                    {planNote(row.plan)}
                                </span>
                            )}
                        </span>
                    )
                }
                renderGroupCell={(row, column) =>
                    row.kind === 'group' ? (
                        <AvailabilityCell
                            night={availabilityByType
                                .get(row.roomType.id)
                                ?.get(column.date)}
                        />
                    ) : null
                }
                renderFooterLabel={() => (
                    <span className="text-xs font-semibold tracking-wide text-grid-header-foreground uppercase">
                        Occupancy rate
                    </span>
                )}
                renderFooterCell={(column) => {
                    const day = occupancyOn(shownRoomTypes, column.date);

                    return (
                        <div
                            title={`${day.sold} of ${day.units} sellable units sold`}
                            className="flex h-full flex-col items-center justify-center gap-0.5 pb-2 text-xs tabular-nums"
                        >
                            <Progress
                                value={day.percent}
                                aria-label={`Occupancy ${day.percent}%`}
                                className={BAR_CLASS}
                            />
                            <span className="text-sm font-semibold">
                                {day.percent}%
                            </span>
                            <span className="text-grid-muted-foreground">
                                {day.sold}/{day.units}
                            </span>
                        </div>
                    );
                }}
                footerHeight={64}
                renderCell={(row, column) => {
                    if (row.kind !== 'row') {
                        return null;
                    }

                    const cell = cellsByPlan
                        .get(`${row.roomType.id}-${row.plan.id}`)
                        ?.get(column.date);
                    const content = (
                        <RateCell
                            cell={cell}
                            derived={row.plan.derived}
                            stayNights={
                                row.plan.pricingBasis === 'package'
                                    ? row.plan.fixedNights
                                    : null
                            }
                        />
                    );

                    if (!canManage) {
                        return content;
                    }

                    return (
                        <button
                            type="button"
                            aria-label={`Edit ${row.roomType.name}, ${row.plan.name}, ${column.date}: ${cell ? cellTitle(cell, row.plan.derived, row.plan.pricingBasis === 'package' ? row.plan.fixedNights : null) || 'no price' : 'no rate'}`}
                            className="block h-full w-full cursor-pointer outline-none focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:ring-inset"
                            onClick={() =>
                                setEditing({
                                    roomType: row.roomType,
                                    plan: row.plan,
                                    cell,
                                    target: {
                                        ratePlanId: row.plan.id,
                                        roomTypeId: row.roomType.id,
                                        date: column.date,
                                        isoWeekday: column.isoWeekday,
                                    },
                                })
                            }
                        >
                            {content}
                        </button>
                    );
                }}
            />
            {canManage && (
                <RateCellSheet
                    editing={editing}
                    currency={currency}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
