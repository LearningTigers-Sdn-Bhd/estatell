import { Link } from '@inertiajs/react';
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
import { buttonVariants } from '@/components/ui/button';
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

export type DataTablePagination = {
    currentPage: number;
    lastPage: number;
    from: number | null;
    to: number | null;
    total: number;
    prevUrl: string | null;
    nextUrl: string | null;
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
                                                    className="-ml-1 flex items-center gap-1 rounded px-1 hover:text-foreground"
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
                                        <TableCell key={cell.id}>
                                            <table.FlexRender cell={cell} />
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </div>

            {pagination.total > 0 && (
                <nav
                    aria-label="Pagination"
                    className="flex items-center justify-between gap-3 text-sm text-muted-foreground"
                >
                    <p className="tabular-nums">
                        Showing {pagination.from}–{pagination.to} of{' '}
                        {pagination.total}
                    </p>
                    {pagination.lastPage > 1 && (
                        <div className="flex items-center gap-2">
                            <PageLink href={pagination.prevUrl}>
                                Previous
                            </PageLink>
                            <span className="tabular-nums">
                                Page {pagination.currentPage} of{' '}
                                {pagination.lastPage}
                            </span>
                            <PageLink href={pagination.nextUrl}>Next</PageLink>
                        </div>
                    )}
                </nav>
            )}
        </div>
    );
}

function PageLink({
    href,
    children,
}: {
    href: string | null;
    children: ReactNode;
}) {
    if (!href) {
        return (
            <span
                aria-disabled
                className={cn(
                    buttonVariants({ variant: 'outline', size: 'sm' }),
                    'pointer-events-none opacity-50',
                )}
            >
                {children}
            </span>
        );
    }

    return (
        <Link
            href={href}
            preserveScroll
            preserveState
            className={buttonVariants({ variant: 'outline', size: 'sm' })}
        >
            {children}
        </Link>
    );
}
