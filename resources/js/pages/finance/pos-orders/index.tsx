import { Head, router } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import { useMemo } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { index, show } from '@/routes/finance/pos-orders';

type Row = App.Data.Pos.PosOrderRowData;
type Filters = {
    outlet?: string;
    status?: string;
    from?: string;
    to?: string;
    q?: string;
};

type Props = {
    orders: {
        data: Row[];
        meta: {
            current_page: number;
            last_page: number;
            from: number | null;
            to: number | null;
            total: number;
            prev_page_url: string | null;
            next_page_url: string | null;
        };
    };
    outlets: { id: number; name: string }[];
    filters: Filters;
    currency: App.Data.Property.CurrencyData | null;
};

const column = dataTableColumns<Row>();

export default function PosOrdersIndex({
    orders,
    outlets,
    filters,
    currency,
}: Props) {
    const currencyCode = currency?.code ?? 'MYR';

    const columns = useMemo(
        () => [
            column.accessor('orderNumber', {
                header: 'Receipt',
                enableSorting: false,
                cell: (info) => (
                    <span className="font-medium group-hover/row:underline">
                        {info.getValue()}
                    </span>
                ),
            }),
            column.accessor('businessDate', {
                header: 'Date',
                enableSorting: false,
            }),
            column.accessor('outletName', {
                header: 'Outlet',
                enableSorting: false,
            }),
            column.accessor('orderType', {
                header: 'Type',
                enableSorting: false,
                cell: (info) =>
                    info.getValue() === 'refund' ? 'Refund' : 'Sale',
            }),
            column.accessor('statusLabel', {
                header: 'Status',
                enableSorting: false,
                cell: (info) => (
                    <Badge
                        variant={
                            info.row.original.status === 'voided'
                                ? 'destructive'
                                : 'secondary'
                        }
                    >
                        {info.getValue()}
                    </Badge>
                ),
            }),
            column.accessor('grandTotal', {
                header: `Total (${currencyCode})`,
                enableSorting: false,
                cell: (info) => (
                    <MoneyText
                        amount={info.getValue()}
                        currency={currency}
                        plain
                    />
                ),
            }),
            column.accessor('onEInvoice', {
                header: 'E-invoice',
                enableSorting: false,
                cell: (info) =>
                    info.getValue() ? (
                        <Badge variant="secondary">Filed</Badge>
                    ) : (
                        <span className="text-muted-foreground">Not filed</span>
                    ),
            }),
        ],
        [currency, currencyCode],
    );

    function visit(change: Partial<Filters>) {
        const next = Object.fromEntries(
            Object.entries({ ...filters, ...change }).filter(
                ([, value]) => value !== undefined && value !== '',
            ),
        );

        router.get(
            index.url(undefined, { query: next }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <>
            <Head title="POS receipts" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="POS receipts"
                    description="Settled and voided POS sales and refunds."
                />
                <div className="flex flex-wrap items-center gap-2">
                    <EnumSelect
                        name="outlet"
                        options={outlets.map((outlet) => ({
                            value: String(outlet.id),
                            label: outlet.name,
                        }))}
                        value={filters.outlet ?? ''}
                        onValueChange={(value) => visit({ outlet: value })}
                        allLabel="All outlets"
                        aria-label="Outlet"
                    />
                    <EnumSelect
                        name="status"
                        options={[
                            { value: 'settled', label: 'Settled' },
                            { value: 'voided', label: 'Voided' },
                        ]}
                        value={filters.status ?? ''}
                        onValueChange={(value) => visit({ status: value })}
                        allLabel="All statuses"
                        aria-label="Status"
                    />
                    <Input
                        type="date"
                        aria-label="From"
                        className="w-40"
                        value={filters.from ?? ''}
                        onChange={(event) =>
                            visit({ from: event.target.value })
                        }
                    />
                    <Input
                        type="date"
                        aria-label="To"
                        className="w-40"
                        value={filters.to ?? ''}
                        onChange={(event) => visit({ to: event.target.value })}
                    />
                    <Input
                        type="search"
                        aria-label="Receipt number"
                        placeholder="Receipt number"
                        className="w-48"
                        defaultValue={filters.q ?? ''}
                        onKeyDown={(event) =>
                            event.key === 'Enter' &&
                            visit({ q: event.currentTarget.value })
                        }
                    />
                </div>
                <DataTable
                    columns={columns}
                    data={orders.data}
                    getRowId={(row) => String(row.id)}
                    sort=""
                    onSortChange={() => undefined}
                    onRowClick={(row) =>
                        router.visit(show.url({ order: row.id }))
                    }
                    pagination={{
                        currentPage: orders.meta.current_page,
                        lastPage: orders.meta.last_page,
                        from: orders.meta.from,
                        to: orders.meta.to,
                        total: orders.meta.total,
                        prevUrl: orders.meta.prev_page_url,
                        nextUrl: orders.meta.next_page_url,
                    }}
                    empty={
                        <EmptyState
                            icon={Receipt}
                            title="No POS receipts"
                            description="Receipts appear here as the registers settle orders."
                        />
                    }
                />
            </div>
        </>
    );
}

PosOrdersIndex.layout = {
    breadcrumbs: [{ title: 'POS receipts', href: index() }],
};
