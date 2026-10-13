import {
    createColumnHelper,
    functionalUpdate,
    rowSortingFeature,
    tableFeatures,
    useTable,
} from '@tanstack/react-table';
import type { ColumnDef, RowData, SortingState } from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { PaginationFooter } from '@/components/pms/pagination-footer';
import type { PaginationData } from '@/components/pms/pagination-footer';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

const features = tableFeatures({ rowSortingFeature });

type Features = typeof features;

// eslint-disable-next-line @typescript-eslint/no-explicit-any -- columns hold mixed value types
export type DataTableColumn<TData extends RowData> = ColumnDef<
    Features,
    TData,
    any
>;

/**
 * Column helper bound to the features `DataTable` uses.
 */
export function dataTableColumns<TData extends RowData>() {
    return createColumnHelper<Features, TData>();
}

export type ColumnAlignment = 'left' | 'center' | 'right';

const TEXT_ALIGN: Record<ColumnAlignment, string> = {
    left: 'text-left',
    center: 'text-center',
    right: 'text-right',
};

const SORT_ALIGN: Record<ColumnAlignment, string> = {
    left: '',
    center: 'mx-auto',
    right: '-mr-1 ml-auto',
};

export type DataTablePagination = PaginationData;

export type DataTableSelection = {
    /** Ids (from `getRowId`) of the selected rows, on every page. */
    selected: Set<string>;
    onChange: (next: Set<string>) => void;
};

type Props<TData extends RowData> = {
    columns: DataTableColumn<TData>[];
    data: TData[];
    getRowId: (row: TData) => string;
    /** Server sort in query-builder form: `name` or `-name`. */
    sort: string;
    onSortChange: (sort: string) => void;
    pagination: DataTablePagination;
    onRowClick?: (row: TData) => void;
    /** Alignment by column id, for numbers and money. A column with no entry aligns left. */
    columnAlign?: Record<string, ColumnAlignment>;
    /** Adds a leading checkbox column. The header checkbox selects or clears the rows on this page. */
    selection?: DataTableSelection;
    /** A total row under the rows: the content of each cell by column id. A column with no entry stays empty. */
    footer?: Record<string, ReactNode>;
    /** Full-width rows by row id: `before` is drawn above the row (a section heading), `after` below it (a subtotal). */
    rowDecorations?: Record<string, { before?: ReactNode; after?: ReactNode }>;
    empty: ReactNode;
};

function toSortingState(sort: string): SortingState {
    if (!sort) {
        return [];
    }

    return [{ id: sort.replace(/^-/, ''), desc: sort.startsWith('-') }];
}

function toSort(sorting: SortingState): string {
    const [first] = sorting;

    return first ? `${first.desc ? '-' : ''}${first.id}` : '';
}

/**
 * shadcn table with TanStack Table in server mode: sorting and pages come from and go to the URL.
 */
export function DataTable<TData extends RowData>({
    columns,
    data,
    getRowId,
    sort,
    onSortChange,
    pagination,
    onRowClick,
    columnAlign = {},
    selection,
    footer,
    rowDecorations = {},
    empty,
}: Props<TData>) {
    const sorting = toSortingState(sort);

    const table = useTable({
        features,
        columns,
        data,
        getRowId,
        manualSorting: true,
        enableMultiSort: false,
        enableSortingRemoval: false,
        state: { sorting },
        onSortingChange: (updater) =>
            onSortChange(toSort(functionalUpdate(updater, sorting))),
    });

    const rows = table.getRowModel().rows;
    const pageIds = rows.map((row) => row.id);
    const selectedOnPage = selection
        ? pageIds.filter((id) => selection.selected.has(id)).length
        : 0;

    const togglePage = (checked: boolean) => {
        if (!selection) {
            return;
        }

        const next = new Set(selection.selected);

        for (const id of pageIds) {
            if (checked) {
                next.add(id);
            } else {
                next.delete(id);
            }
        }

        selection.onChange(next);
    };

    const toggleRow = (id: string, checked: boolean) => {
        if (!selection) {
            return;
        }

        const next = new Set(selection.selected);

        if (checked) {
            next.add(id);
        } else {
            next.delete(id);
        }

        selection.onChange(next);
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="overflow-hidden rounded-lg border">
                <Table>
                    <TableHeader>
                        {table.getHeaderGroups().map((group) => (
                            <TableRow key={group.id}>
                                {selection && (
                                    <TableHead className="w-8">
                                        <Checkbox
                                            aria-label="Select all rows on this page"
                                            checked={
                                                pageIds.length > 0 &&
                                                selectedOnPage ===
                                                    pageIds.length
                                            }
                                            indeterminate={
                                                selectedOnPage > 0 &&
                                                selectedOnPage < pageIds.length
                                            }
                                            disabled={pageIds.length === 0}
                                            onCheckedChange={togglePage}
                                        />
                                    </TableHead>
                                )}
                                {group.headers.map((header) => {
                                    const sorted = header.column.getIsSorted();
                                    const SortIcon =
                                        sorted === 'asc'
                                            ? ArrowUp
                                            : sorted === 'desc'
                                              ? ArrowDown
                                              : ArrowUpDown;

                                    return (
                                        <TableHead
                                            key={header.id}
                                            className={cn(
                                                TEXT_ALIGN[
                                                    columnAlign[
                                                        header.column.id
                                                    ] ?? 'left'
                                                ],
                                            )}
                                            aria-sort={
                                                sorted === 'asc'
                                                    ? 'ascending'
                                                    : sorted === 'desc'
                                                      ? 'descending'
                                                      : undefined
                                            }
                                        >
                                            {header.isPlaceholder ? null : header.column.getCanSort() ? (
                                                <button
                                                    type="button"
                                                    onClick={header.column.getToggleSortingHandler()}
                                                    className={cn(
                                                        '-ml-1 flex items-center gap-1 rounded px-1 hover:text-foreground',
                                                        SORT_ALIGN[
                                                            columnAlign[
                                                                header.column.id
                                                            ] ?? 'left'
                                                        ],
                                                    )}
                                                >
                                                    <table.FlexRender
                                                        header={header}
                                                    />
                                                    <SortIcon className="size-3.5 text-muted-foreground" />
                                                </button>
                                            ) : (
                                                <table.FlexRender
                                                    header={header}
                                                />
                                            )}
                                        </TableHead>
                                    );
                                })}
                            </TableRow>
                        ))}
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableRow>
                                <TableCell
                                    colSpan={
                                        columns.length + (selection ? 1 : 0)
                                    }
                                >
                                    {empty}
                                </TableCell>
                            </TableRow>
                        ) : (
                            rows.map((row) => (
                                <Fragment key={row.id}>
                                    {rowDecorations[row.id]?.before && (
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell
                                                colSpan={
                                                    columns.length +
                                                    (selection ? 1 : 0)
                                                }
                                                className="bg-muted/50 font-medium"
                                            >
                                                {rowDecorations[row.id]?.before}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    <TableRow
                                        onClick={
                                            onRowClick
                                                ? () => onRowClick(row.original)
                                                : undefined
                                        }
                                        className={cn(
                                            onRowClick &&
                                                'group/row cursor-pointer',
                                        )}
                                    >
                                        {selection && (
                                            <TableCell
                                                className="w-8"
                                                onClick={(event) =>
                                                    event.stopPropagation()
                                                }
                                            >
                                                <Checkbox
                                                    aria-label="Select row"
                                                    checked={selection.selected.has(
                                                        row.id,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        toggleRow(
                                                            row.id,
                                                            checked,
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                        )}
                                        {row.getAllCells().map((cell) => (
                                            <TableCell
                                                key={cell.id}
                                                className={cn(
                                                    TEXT_ALIGN[
                                                        columnAlign[
                                                            cell.column.id
                                                        ] ?? 'left'
                                                    ],
                                                )}
                                            >
                                                <table.FlexRender cell={cell} />
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                    {rowDecorations[row.id]?.after && (
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell
                                                colSpan={
                                                    columns.length +
                                                    (selection ? 1 : 0)
                                                }
                                                className="bg-muted/50"
                                            >
                                                {rowDecorations[row.id]?.after}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </Fragment>
                            ))
                        )}
                    </TableBody>
                    {footer && rows.length > 0 && (
                        <TableFooter>
                            <TableRow>
                                {selection && <TableCell className="w-8" />}
                                {table.getAllColumns().map((column) => (
                                    <TableCell
                                        key={column.id}
                                        className={cn(
                                            'font-medium',
                                            TEXT_ALIGN[
                                                columnAlign[column.id] ?? 'left'
                                            ],
                                        )}
                                    >
                                        {footer[column.id]}
                                    </TableCell>
                                ))}
                            </TableRow>
                        </TableFooter>
                    )}
                </Table>
            </div>

            <PaginationFooter pagination={pagination} />
        </div>
    );
}
