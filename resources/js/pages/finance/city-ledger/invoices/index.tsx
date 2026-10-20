import { Head, router } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import { useCallback, useMemo } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { index, show } from '@/routes/finance/city-ledger/invoices';

type Row = App.Data.CityLedger.ArInvoiceRowData;
type Filters = { search: string; type: string; status: string };

type Props = {
    invoices: Illuminate.LengthAwarePaginator<number, Row>;
    filters: Filters;
    sort: string;
    types: EnumOption[];
    statuses: EnumOption[];
    currency: App.Data.Property.CurrencyData | null;
};

const column = dataTableColumns<Row>();

export default function InvoicesIndex({
    invoices,
    filters,
    sort,
    types,
    statuses,
    currency,
}: Props) {
    const columns = useMemo(
        () => [
            column.accessor('number', {
                header: 'Number',
                cell: (info) => (
                    <span className="flex items-center gap-2">
                        <span className="font-medium group-hover/row:underline">
                            {info.getValue()}
                        </span>
                        {info.row.original.migrated && (
                            <Badge variant="secondary">Migrated</Badge>
                        )}
                    </span>
                ),
            }),
            column.accessor('companyName', {
                header: 'Company',
                enableSorting: false,
            }),
            column.accessor('typeLabel', {
                header: 'Type',
                enableSorting: false,
            }),
            column.accessor('issuedOn', {
                id: 'issued_on',
                header: 'Issued',
                cell: (info) => <DateText date={info.getValue()} />,
            }),
            column.accessor('dueOn', {
                id: 'due_on',
                header: 'Due',
                cell: (info) =>
                    info.getValue() === null ? (
                        '—'
                    ) : (
                        <span
                            className={cn(
                                info.row.original.overdue && 'text-destructive',
                            )}
                        >
                            <DateText date={info.getValue() ?? ''} />
                            {info.row.original.overdue && ' · Overdue'}
                        </span>
                    ),
            }),
            column.accessor('amount', {
                header: 'Amount',
                cell: (info) => (
                    <MoneyText amount={info.getValue()} currency={currency} />
                ),
            }),
            column.accessor('balance', {
                header: 'Balance',
                enableSorting: false,
                cell: (info) =>
                    info.getValue() === null ? (
                        '—'
                    ) : (
                        <MoneyText
                            amount={info.getValue() ?? '0.00'}
                            currency={currency}
                        />
                    ),
            }),
            column.accessor('status', {
                header: 'Status',
                enableSorting: false,
                cell: (info) => <StatusBadge status={info.getValue()} />,
            }),
        ],
        [currency],
    );

    const visit = useCallback(
        (changes: Partial<Filters & { sort: string }>) => {
            const next = { ...filters, sort, ...changes };
            const filter = Object.fromEntries(
                (['search', 'type', 'status'] as const)
                    .filter((key) => next[key] !== '')
                    .map((key) => [key, next[key]]),
            );

            router.get(
                index.url(undefined, {
                    query: {
                        ...(Object.keys(filter).length ? { filter } : {}),
                        ...(next.sort ? { sort: next.sort } : {}),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [filters, sort],
    );

    const hasFilters = Boolean(
        filters.search || filters.type || filters.status,
    );

    return (
        <>
            <Head title="Invoices" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Invoices"
                    description="What companies owe the property, and the credit notes that correct it."
                />

                <FilterBar
                    search={filters.search}
                    onSearchChange={(search) => visit({ search })}
                    searchPlaceholder="Search number or company"
                >
                    <EnumSelect
                        options={types}
                        value={filters.type}
                        onValueChange={(type) => visit({ type })}
                        allLabel="All types"
                        aria-label="Type"
                        className="w-44"
                    />
                    <EnumSelect
                        options={statuses}
                        value={filters.status}
                        onValueChange={(status) => visit({ status })}
                        allLabel="All statuses"
                        aria-label="Status"
                        className="w-44"
                    />
                </FilterBar>

                <DataTable
                    columns={columns}
                    data={invoices.data}
                    getRowId={(row) => String(row.id)}
                    sort={sort || '-issued_on'}
                    onSortChange={(next) => visit({ sort: next })}
                    onRowClick={(row) =>
                        router.visit(show.url({ invoice: row.id }))
                    }
                    pagination={{
                        currentPage: invoices.meta.current_page,
                        lastPage: invoices.meta.last_page,
                        from: invoices.meta.from,
                        to: invoices.meta.to,
                        total: invoices.meta.total,
                        prevUrl: invoices.meta.prev_page_url,
                        nextUrl: invoices.meta.next_page_url,
                    }}
                    empty={
                        hasFilters ? (
                            <EmptyState
                                icon={Receipt}
                                title="No invoices match these filters"
                                action={
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            visit({
                                                search: '',
                                                type: '',
                                                status: '',
                                            })
                                        }
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={Receipt}
                                title="No invoices yet"
                                description="An invoice is issued when a folio paid by a company is settled to City ledger."
                            />
                        )
                    }
                />
            </div>
        </>
    );
}

InvoicesIndex.layout = {
    breadcrumbs: [{ title: 'Invoices', href: index() }],
};
