import { Ban, ChevronDown, ChevronRight, Link2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { Ref } from 'react';
import { buildDateColumns } from '@/components/pms/grid/date-columns';
import { DateGrid } from '@/components/pms/grid/date-grid';
import type { DateGridHandle } from '@/components/pms/grid/date-grid';
import { cn } from '@/lib/utils';
import { RateCellSheet } from './rate-cell-sheet';
import type { EditingCell } from './rate-cell-sheet';
import {
    buildRows,
    cellFlags,
    formatAmount,
    indexCells,
    nightsLabel,
    planNote,
    stayLimit,
    toggleCollapsed,
} from './calendar-model';
import type { CalendarFilters, CalendarRow } from './calendar-model';

type Cell = App.Data.Rates.RateCalendarCellData;

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
    const parts = [
        cell.amount === null
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
                className="rounded-none border-x-0 border-b-0"
                labelWidth={288}
                bottomGap={0}
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
                            <span className="truncate text-sm">
                                {row.plan.name}
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
