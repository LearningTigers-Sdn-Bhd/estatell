import { useVirtualizer } from '@tanstack/react-virtual';
import { useRef } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { DateColumn } from './date-columns';

export type GridRow = {
    key: string;
    /** A group row is a full-width heading. A plain row has a cell for each column. */
    kind: 'group' | 'row';
};

type Props<Row extends GridRow> = {
    rows: Row[];
    columns: DateColumn[];
    renderLabel: (row: Row) => ReactNode;
    renderCell: (row: Row, column: DateColumn) => ReactNode;
    'aria-label': string;
    rowHeight?: number;
    groupHeight?: number;
    columnWidth?: number;
    labelWidth?: number;
    headerHeight?: number;
    maxHeight?: string;
};

/**
 * The shared date grid engine: a sticky label column, a sticky date header and cells for each row and date.
 * Row and column heights are fixed, and only the rows and columns in view are rendered.
 */
export function DateGrid<Row extends GridRow>({
    rows,
    columns,
    renderLabel,
    renderCell,
    'aria-label': ariaLabel,
    rowHeight = 48,
    groupHeight = 36,
    columnWidth = 88,
    labelWidth = 208,
    headerHeight = 48,
    maxHeight = '70vh',
}: Props<Row>) {
    const scrollRef = useRef<HTMLDivElement>(null);

    const rowVirtualizer = useVirtualizer({
        count: rows.length,
        getScrollElement: () => scrollRef.current,
        estimateSize: (index) =>
            rows[index].kind === 'group' ? groupHeight : rowHeight,
        overscan: 6,
    });
    const columnVirtualizer = useVirtualizer({
        horizontal: true,
        count: columns.length,
        getScrollElement: () => scrollRef.current,
        estimateSize: () => columnWidth,
        overscan: 4,
    });

    const totalWidth = labelWidth + columnVirtualizer.getTotalSize();
    const totalHeight = headerHeight + rowVirtualizer.getTotalSize();
    const virtualColumns = columnVirtualizer.getVirtualItems();

    return (
        <div
            ref={scrollRef}
            role="grid"
            aria-label={ariaLabel}
            aria-rowcount={rows.length + 1}
            aria-colcount={columns.length + 1}
            className="relative overflow-auto rounded-lg border bg-background"
            style={{ maxHeight }}
        >
            <div
                className="relative"
                style={{ width: totalWidth, height: totalHeight }}
            >
                <div
                    role="row"
                    className="sticky top-0 z-20 border-b bg-muted"
                    style={{ width: totalWidth, height: headerHeight }}
                >
                    <div
                        role="columnheader"
                        className="sticky left-0 z-10 h-full border-r bg-muted"
                        style={{ width: labelWidth }}
                    />
                    {virtualColumns.map((virtualColumn) => {
                        const column = columns[virtualColumn.index];

                        return (
                            <div
                                key={column.date}
                                role="columnheader"
                                aria-label={column.date}
                                className={cn(
                                    'absolute top-0 flex h-full flex-col items-center justify-center border-r text-xs text-muted-foreground tabular-nums',
                                    column.isToday &&
                                        'bg-accent font-medium text-foreground',
                                )}
                                style={{
                                    left: labelWidth + virtualColumn.start,
                                    width: virtualColumn.size,
                                }}
                            >
                                <span>{column.weekday}</span>
                                <span className="text-sm text-foreground">
                                    {column.day}
                                </span>
                            </div>
                        );
                    })}
                </div>
                {rowVirtualizer.getVirtualItems().map((virtualRow) => {
                    const row = rows[virtualRow.index];
                    const isGroup = row.kind === 'group';

                    return (
                        <div
                            key={row.key}
                            role="row"
                            aria-rowindex={virtualRow.index + 2}
                            className={cn(
                                'absolute left-0 border-b',
                                isGroup && 'bg-muted/50',
                            )}
                            style={{
                                top: headerHeight + virtualRow.start,
                                height: virtualRow.size,
                                width: totalWidth,
                            }}
                        >
                            <div
                                role="rowheader"
                                className={cn(
                                    'sticky left-0 z-10 flex h-full items-center border-r px-3',
                                    isGroup ? 'bg-muted' : 'bg-background',
                                )}
                                style={{ width: labelWidth }}
                            >
                                {renderLabel(row)}
                            </div>
                            {!isGroup &&
                                virtualColumns.map((virtualColumn) => {
                                    const column = columns[virtualColumn.index];

                                    return (
                                        <div
                                            key={column.date}
                                            role="gridcell"
                                            className={cn(
                                                'absolute top-0 h-full border-r',
                                                column.isToday &&
                                                    'bg-accent/40',
                                            )}
                                            style={{
                                                left:
                                                    labelWidth +
                                                    virtualColumn.start,
                                                width: virtualColumn.size,
                                            }}
                                        >
                                            {renderCell(row, column)}
                                        </div>
                                    );
                                })}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
