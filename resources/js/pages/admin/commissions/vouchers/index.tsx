import { Head, router } from '@inertiajs/react';
import { ReceiptText } from 'lucide-react';
import { useCallback } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes/admin';
import { index, show } from '@/routes/admin/commissions/vouchers';

type Row = App.Data.Commissions.PayoutVoucherRowData;
type Filters = { cycle: string; status: string; property: string };

type Props = {
    vouchers: Illuminate.LengthAwarePaginator<number, Row>;
    filters: Filters;
    sort: string;
    cycles: EnumOption[];
    statuses: EnumOption[];
    properties: EnumOption[];
};

const column = dataTableColumns<Row>();

const columns = [
    column.accessor('number', {
        header: 'Voucher',
        enableSorting: false,
        cell: (info) => (
            <span className="font-medium tabular-nums">{info.getValue()}</span>
        ),
    }),
    column.accessor('propertyName', {
        header: 'Property',
        enableSorting: false,
    }),
    column.accessor('periodEnd', {
        id: 'period_end',
        header: 'Cycle',
        cell: (info) => (
            <span className="whitespace-nowrap">
                <DateText date={info.row.original.periodStart} /> –{' '}
                <DateText date={info.getValue()} />
            </span>
        ),
    }),
    column.accessor('dueOn', {
        header: 'Due on',
        enableSorting: false,
        cell: (info) => <DateText date={info.getValue()} />,
    }),
    column.accessor('amount', {
        id: 'amount',
        header: 'Amount',
        cell: (info) => (
            <MoneyText
                amount={info.getValue()}
                currency={info.row.original.currency}
            />
        ),
    }),
    column.accessor('owedToEstatell', {
        header: 'Direction',
        enableSorting: false,
        cell: (info) => (info.getValue() ? 'To Estatell' : 'To property'),
    }),
    column.accessor('status', {
        header: 'Status',
        enableSorting: false,
        cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
];

/**
 * Every payout voucher, newest cycle first, filtered by cycle, status and property in the URL.
 * See docs/30-commission-fees/05-platform-pages.md#vouchers-tab.
 */
export default function PayoutVouchersIndex({
    vouchers,
    filters,
    sort,
    cycles,
    statuses,
    properties,
}: Props) {
    const visit = useCallback(
        (changes: Partial<Filters & { sort: string }>) => {
            const next = { ...filters, sort, ...changes };
            const filter = Object.fromEntries(
                (['cycle', 'status', 'property'] as const)
                    .filter((key) => next[key] !== '')
                    .map((key) => [key, next[key]]),
            );

            router.get(
                index.url({
                    query: {
                        ...(Object.keys(filter).length ? { filter } : {}),
                        ...(next.sort && next.sort !== '-period_end'
                            ? { sort: next.sort }
                            : {}),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [filters, sort],
    );

    const hasFilters = Boolean(
        filters.cycle || filters.status || filters.property,
    );

    return (
        <>
            <Head title="Payout vouchers" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Payout vouchers"
                    description="One voucher per property for each cycle from the 16th to the 15th, paid on the 1st."
                />

                <div className="flex flex-wrap items-center gap-2">
                    <EnumSelect
                        options={cycles}
                        value={filters.cycle}
                        onValueChange={(cycle) => visit({ cycle })}
                        allLabel="All cycles"
                        aria-label="Cycle"
                        className="w-60"
                    />
                    <EnumSelect
                        options={statuses}
                        value={filters.status}
                        onValueChange={(status) => visit({ status })}
                        allLabel="All statuses"
                        aria-label="Status"
                        className="w-44"
                    />
                    <EnumSelect
                        options={properties}
                        value={filters.property}
                        onValueChange={(property) => visit({ property })}
                        allLabel="All properties"
                        aria-label="Property"
                        className="w-52"
                    />
                </div>

                <DataTable
                    columns={columns}
                    data={vouchers.data}
                    getRowId={(row) => String(row.id)}
                    columnAlign={{ amount: 'right' }}
                    sort={sort}
                    onSortChange={(next) => visit({ sort: next })}
                    onRowClick={(row) => router.visit(show(row.id))}
                    pagination={{
                        currentPage: vouchers.meta.current_page,
                        lastPage: vouchers.meta.last_page,
                        from: vouchers.meta.from,
                        to: vouchers.meta.to,
                        total: vouchers.meta.total,
                        prevUrl: vouchers.meta.prev_page_url,
                        nextUrl: vouchers.meta.next_page_url,
                    }}
                    empty={
                        hasFilters ? (
                            <EmptyState
                                icon={ReceiptText}
                                title="No vouchers match these filters"
                                action={
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            visit({
                                                cycle: '',
                                                status: '',
                                                property: '',
                                            })
                                        }
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={ReceiptText}
                                title="No vouchers yet"
                                description="The first vouchers are made on the 16th."
                            />
                        )
                    }
                />
            </div>
        </>
    );
}

PayoutVouchersIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Payout vouchers', href: index() },
    ],
};
