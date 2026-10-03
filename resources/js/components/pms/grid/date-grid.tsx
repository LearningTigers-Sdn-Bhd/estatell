import { useVirtualizer } from '@tanstack/react-virtual';
import { useImperativeHandle, useRef, useState } from 'react';
import type { ReactNode, Ref } from 'react';
import { cn } from '@/lib/utils';
import type { DateColumn } from './date-columns';
import { useViewportFit } from './use-viewport-fit';

export type GridRow = {
    key: string;
    /** A group row is a full-width heading. A plain row has a cell for each column. */
    kind: 'group' | 'row';
};

/** The row and date under the pointer. A row label sets only the row, and a date header sets only the date. */
type Hover = { rowKey: string | null; date: string | null };

/** What a parent can ask the grid to do. */
export type DateGridHandle = {
    /** Scroll sideways so the date's column is the first one after the label column. */
    scrollToDate: (date: string) => void;
};

type Props<Row extends GridRow> = {
    ref?: Ref<DateGridHandle>;
    className?: string;
    rows: Row[];
    columns: DateColumn[];
    renderLabel: (row: Row) => ReactNode;
    renderCell: (row: Row, column: DateColumn) => ReactNode;
    /** Content for the date cells of a group row. A group row has none unless this is set. */
    renderGroupCell?: (row: Row, column: DateColumn) => ReactNode;
    /** A row that stays at the bottom of the grid while the rows scroll: the label, then a cell for each date. */
    renderFooterLabel?: () => ReactNode;
    renderFooterCell?: (column: DateColumn) => ReactNode;
    footerHeight?: number;
    'aria-label': string;
    rowHeight?: number;
    groupHeight?: number;
    columnWidth?: number;
    labelWidth?: number;
    headerHeight?: number;
    /** Room to leave below the grid, in pixels. */
    bottomGap?: number;
    /**
     * Fill the height the parent gives it, for a parent that is a fixed-height flex column (the `fixed` page
     * layout). The grid then does not measure the window.
     */
    fill?: boolean;
};

/**
 * The shared date grid engine: a sticky label column, a sticky date header and cells for each row and date.
 * Row and column heights are fixed, and only the rows and columns in view are rendered.
 */
export function DateGrid<Row extends GridRow>({
    ref,
    className,
    rows,
    columns,
    renderLabel,
    renderCell,
    renderGroupCell,
    renderFooterLabel,
    renderFooterCell,
    footerHeight = 44,
    'aria-label': ariaLabel,
    rowHeight = 48,
    groupHeight = 36,
    columnWidth = 88,
    labelWidth = 208,
    headerHeight = 48,
    bottomGap = 16,
    fill = false,
}: Props<Row>) {
    const scrollRef = useRef<HTMLDivElement>(null);
    const maxHeight = useViewportFit(scrollRef, {
        bottomGap,
        enabled: !fill,
    });

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
        scrollPaddingStart: labelWidth,
    });

    useImperativeHandle(ref, () => ({
        scrollToDate: (date) => {
            const index = columns.findIndex((column) => column.date === date);

            if (index >= 0) {
                columnVirtualizer.scrollToIndex(index, { align: 'start' });
            }
        },
    }));

    const [hover, setHover] = useState<Hover>({ rowKey: null, date: null });

    const changeHover = (rowKey: string | null, date: string | null) =>
        setHover((current) =>
            current.rowKey === rowKey && current.date === date
                ? current
                : { rowKey, date },
        );

    const totalWidth = labelWidth + columnVirtualizer.getTotalSize();
    const rowsHeight = rowVirtualizer.getTotalSize();
    const hasFooter = renderFooterCell !== undefined;
    const virtualColumns = columnVirtualizer.getVirtualItems();

    return (
        <div
            ref={scrollRef}
            role="grid"
            aria-label={ariaLabel}
            aria-rowcount={rows.length + 1}
            aria-colcount={columns.length + 1}
            className={cn(
                'relative overflow-auto overscroll-none rounded-lg border border-grid-line bg-grid-surface text-grid-foreground',
                fill && 'min-h-0 flex-1',
                className,
            )}
            style={
                fill
                    ? undefined
                    : hasFooter
                      ? { height: maxHeight }
                      : { maxHeight }
            }
            onMouseLeave={() => changeHover(null, null)}
        >
            <div
                className="relative flex min-h-full flex-col"
                style={{ width: totalWidth }}
            >
                <div
                    role="row"
                    className="sticky top-0 z-20 shrink-0 border-b border-grid-line bg-grid-header"
                    style={{ width: totalWidth, height: headerHeight }}
                >
                    <div
                        role="columnheader"
                        className="sticky left-0 z-10 h-full border-r border-grid-line bg-grid-header"
                        style={{ width: labelWidth }}
                    />
                    {virtualColumns.map((virtualColumn) => {
                        const column = columns[virtualColumn.index];

                        return (
                            <div
                                key={column.date}
                                role="columnheader"
                                aria-label={column.date}
                                aria-current={
                                    column.isToday ? 'date' : undefined
                                }
                                className={cn(
                                    'absolute top-0 flex h-full flex-col items-center justify-center border-r border-grid-line text-xs text-grid-header-foreground tabular-nums',
                                    column.isWeekend && 'bg-grid-weekend',
                                    hover.date === column.date &&
                                        'bg-grid-hover',
                                    column.isToday &&
                                        'bg-grid-today font-medium text-grid-today-foreground',
                                )}
                                style={{
                                    left: labelWidth + virtualColumn.start,
                                    width: virtualColumn.size,
                                }}
                                onMouseEnter={() =>
                                    changeHover(null, column.date)
                                }
                            >
                                <span>{column.weekday}</span>
                                <span
                                    className={cn(
                                        'text-sm',
                                        column.isToday
                                            ? 'text-grid-today-foreground'
                                            : 'text-grid-foreground',
                                    )}
                                >
                                    {column.day} {column.month}
                                </span>
                            </div>
                        );
                    })}
                </div>
                <div
                    aria-hidden
                    className="flex-1"
                    style={{ minHeight: rowsHeight }}
                />
                {rowVirtualizer.getVirtualItems().map((virtualRow) => {
                    const row = rows[virtualRow.index];
                    const isGroup = row.kind === 'group';

                    return (
                        <div
                            key={row.key}
                            role="row"
                            aria-rowindex={virtualRow.index + 2}
                            className={cn(
                                'absolute left-0 border-b border-grid-line',
                                isGroup && 'bg-grid-group',
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
                                    'sticky left-0 z-10 flex h-full items-center border-r border-grid-line px-3',
                                    isGroup
                                        ? 'bg-grid-group'
                                        : 'bg-grid-surface',
                                )}
                                style={{ width: labelWidth }}
                                onMouseEnter={() =>
                                    changeHover(isGroup ? null : row.key, null)
                                }
                            >
                                {!isGroup && hover.rowKey === row.key && (
                                    <span
                                        aria-hidden
                                        className="pointer-events-none absolute inset-0 bg-grid-hover"
                                    />
                                )}
                                <div className="relative min-w-0 flex-1">
                                    {renderLabel(row)}
                                </div>
                            </div>
                            {isGroup &&
                                renderGroupCell &&
                                virtualColumns.map((virtualColumn) => {
                                    const column = columns[virtualColumn.index];

                                    return (
                                        <div
                                            key={column.date}
                                            role="gridcell"
                                            className="absolute top-0 h-full border-r border-grid-line"
                                            style={{
                                                left:
                                                    labelWidth +
                                                    virtualColumn.start,
                                                width: virtualColumn.size,
                                            }}
                                        >
                                            {renderGroupCell(row, column)}
                                        </div>
                                    );
                                })}
                            {!isGroup &&
                                virtualColumns.map((virtualColumn) => {
                                    const column = columns[virtualColumn.index];

                                    return (
                                        <div
                                            key={column.date}
                                            role="gridcell"
                                            className={cn(
                                                'absolute top-0 h-full border-r border-grid-line',
                                                column.isWeekend &&
                                                    'bg-grid-weekend',
                                                column.isToday &&
                                                    'bg-grid-today-soft',
                                                (hover.rowKey === row.key ||
                                                    hover.date ===
                                                        column.date) &&
                                                    'bg-grid-hover',
                                            )}
                                            style={{
                                                left:
                                                    labelWidth +
                                                    virtualColumn.start,
                                                width: virtualColumn.size,
                                            }}
                                            onMouseEnter={() =>
                                                changeHover(
                                                    row.key,
                                                    column.date,
                                                )
                                            }
                                        >
                                            {renderCell(row, column)}
                                        </div>
                                    );
                                })}
                        </div>
                    );
                })}
                {hasFooter && (
                    <div
                        role="row"
                        className="sticky bottom-0 z-20 shrink-0 border-t border-grid-line bg-grid-header"
                        style={{ width: totalWidth, height: footerHeight }}
                    >
                        <div
                            role="rowheader"
                            className="sticky left-0 z-10 flex h-full items-center border-r border-grid-line bg-grid-header px-3"
                            style={{ width: labelWidth }}
                        >
                            {renderFooterLabel?.()}
                        </div>
                        {virtualColumns.map((virtualColumn) => {
                            const column = columns[virtualColumn.index];

                            return (
                                <div
                                    key={column.date}
                                    role="gridcell"
                                    className={cn(
                                        'absolute top-0 h-full border-r border-grid-line',
                                        column.isToday && 'bg-grid-today-soft',
                                    )}
                                    style={{
                                        left: labelWidth + virtualColumn.start,
                                        width: virtualColumn.size,
                                    }}
                                >
                                    {renderFooterCell(column)}
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}
