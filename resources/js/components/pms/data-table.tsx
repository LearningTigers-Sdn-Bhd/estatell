import {
    createColumnHelper,
    functionalUpdate,
    rowSortingFeature,
    tableFeatures,
    useTable,
} from '@tanstack/react-table';
import type { ColumnDef, RowData, SortingState } from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { PaginationFooter } from '@/components/pms/pagination-footer';
import type { PaginationData } from '@/components/pms/pagination-footer';
import {
    Table,
    TableBody,
    TableCell,
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

export type DataTablePagination = PaginationData;

type Props<TData extends RowData> = {
    columns: DataTableColumn<TData>[];
    data: TData[];
    getRowId: (row: TData) => string;
    /** Server sort in query-builder form: `name` or `-name`. */
    sort: string;
    onSortChange: (sort: string) => void;
    pagination: DataTablePagination;
    onRowClick?: (row: TData) => void;
    /** Ids of the columns whose header and cells align right, for numbers and money. */
    rightAlign?: string[];
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
    rightAlign = [],
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

    return (
        <div className="flex flex-col gap-3">
            <div className="overflow-hidden rounded-lg border">
                <Table>
                    <TableHeader>
                        {table.getHeaderGroups().map((group) => (
                            <TableRow key={group.id}>
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
                                                rightAlign.includes(
                                                    header.column.id,
                                                ) && 'text-right',
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
                                                        rightAlign.includes(
                                                            header.column.id,
                                                        ) && '-mr-1 ml-auto',
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
                                <TableCell colSpan={columns.length}>
                                    {empty}
                                </TableCell>
                            </TableRow>
                        ) : (
                            rows.map((row) => (
                                <TableRow
                                    key={row.id}
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
                                    {row.getAllCells().map((cell) => (
                                        <TableCell
                                            key={cell.id}
                                            className={cn(
                                                rightAlign.includes(
                                                    cell.column.id,
                                                ) && 'text-right',
                                            )}
                                        >
                                            <table.FlexRender cell={cell} />
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </div>

            <PaginationFooter pagination={pagination} />
        </div>
    );
}
