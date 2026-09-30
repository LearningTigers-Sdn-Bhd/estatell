import { Ban, ChevronDown, ChevronRight, Link2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { Ref } from 'react';
import { buildDateColumns } from '@/components/pms/grid/date-columns';
import { DateGrid } from '@/components/pms/grid/date-grid';
import type { DateGridHandle } from '@/components/pms/grid/date-grid';
import { cn } from '@/lib/utils';
import {
    buildRows,
    cellFlags,
    formatAmount,
    indexCells,
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

function cellTitle(cell: Cell, derived: boolean): string {
    const parts = [
        cell.amount === null
            ? null
            : `${formatAmount(cell.amount)}${derived ? ' (follows parent)' : ''}`,
        ...cellFlags(cell).map((flag) => FLAG_LABELS[flag]),
        stayLimit(cell),
    ];

    return parts.filter(Boolean).join(', ');
}

function RateCell({
    cell,
    derived,
}: {
    cell: Cell | undefined;
    derived: boolean;
}) {
    if (!cell) {
        return null;
    }

    const flags = cellFlags(cell);
    const limit = stayLimit(cell);

    return (
        <div
            title={cellTitle(cell, derived)}
            className={cn(
                'flex h-full flex-col items-center justify-center gap-0.5 text-xs tabular-nums',
                cell.stopSell && 'bg-destructive/10',
            )}
        >
            <span className="sr-only">{cellTitle(cell, derived)}</span>
            {cell.amount !== null && (
                <span
                    aria-hidden
                    className={cn(
                        'flex items-center gap-1 text-sm font-medium',
                        cell.stopSell &&
                            'text-grid-muted-foreground line-through',
                        derived && 'italic',
                    )}
                >
                    {derived && <Link2 className="size-3 text-info" />}
                    {formatAmount(cell.amount)}
                </span>
            )}
            {(flags.length > 0 || limit) && (
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
}: {
    gridRef: Ref<DateGridHandle>;
    roomTypes: App.Data.Rates.RateCalendarRoomTypeData[];
    dates: string[];
    today: string;
    /** ISO weekdays whose nights are weekend nights, from the property settings. */
    weekendDays: number[];
    filters: CalendarFilters;
}) {
    const columns = useMemo(
        () => buildDateColumns(dates, today, weekendDays),
        [dates, today, weekendDays],
    );
    const [collapsedIds, setCollapsedIds] = useState<ReadonlySet<number>>(
        new Set(),
    );
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
                        {row.plan.derived && (
                            <span className="truncate text-xs text-grid-muted-foreground">
                                Follows {row.plan.followsName}
                            </span>
                        )}
                    </span>
                )
            }
            renderCell={(row, column) =>
                row.kind === 'row' ? (
                    <RateCell
                        cell={cellsByPlan
                            .get(`${row.roomType.id}-${row.plan.id}`)
                            ?.get(column.date)}
                        derived={row.plan.derived}
                    />
                ) : null
            }
        />
    );
}
