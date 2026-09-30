import { Ban, Link2 } from 'lucide-react';
import { useMemo } from 'react';
import { buildDateColumns } from '@/components/pms/grid/date-columns';
import { DateGrid } from '@/components/pms/grid/date-grid';
import { cn } from '@/lib/utils';
import {
    buildRows,
    cellFlags,
    formatAmount,
    indexCells,
    stayLimit,
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
            {cell.amount !== null && (
                <span
                    className={cn(
                        'flex items-center gap-1 text-sm font-medium',
                        cell.stopSell && 'text-muted-foreground line-through',
                        derived && 'italic',
                    )}
                >
                    {derived && (
                        <Link2 aria-hidden className="size-3 text-info" />
                    )}
                    {formatAmount(cell.amount)}
                </span>
            )}
            {(flags.length > 0 || limit) && (
                <span className="flex items-center gap-1 text-muted-foreground">
                    {cell.stopSell && (
                        <Ban
                            aria-label={FLAG_LABELS['stop-sell']}
                            className="size-3 text-destructive"
                        />
                    )}
                    {cell.closedToArrival && (
                        <span
                            className="text-warning"
                            aria-label={FLAG_LABELS['closed-arrival']}
                        >
                            CTA
                        </span>
                    )}
                    {cell.closedToDeparture && (
                        <span
                            className="text-warning"
                            aria-label={FLAG_LABELS['closed-departure']}
                        >
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
 * One month of daily rates: a heading for each room type and a row for each plan that sells it. A plan that
 * follows another shows the calculated price with a link marker.
 */
export function RateCalendarGrid({
    roomTypes,
    dates,
    today,
    filters,
}: {
    roomTypes: App.Data.Rates.RateCalendarRoomTypeData[];
    dates: string[];
    today: string;
    filters: CalendarFilters;
}) {
    const columns = useMemo(
        () => buildDateColumns(dates, today),
        [dates, today],
    );
    const rows = useMemo(
        () => buildRows(roomTypes, filters),
        [roomTypes, filters],
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
            aria-label="Rate calendar"
            rows={rows}
            columns={columns}
            renderLabel={(row) =>
                row.kind === 'group' ? (
                    <span className="truncate text-sm font-semibold">
                        {row.roomType.name}
                    </span>
                ) : (
                    <span className="flex min-w-0 flex-col">
                        <span className="truncate text-sm">
                            {row.plan.name}
                        </span>
                        {row.plan.derived && (
                            <span className="truncate text-xs text-muted-foreground">
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
