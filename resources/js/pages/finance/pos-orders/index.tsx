import { Head, router, usePage } from '@inertiajs/react';
import { Ban, Receipt, Search, ShoppingBag, Undo2, Wallet } from 'lucide-react';
import { useMemo } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatCard } from '@/components/pms/stat-card';
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { reportDatePresets } from '@/lib/date-presets';
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
    summary: {
        netSales: string;
        sales: number;
        refunds: number;
        refundTotal: string;
        voided: number;
    };
    outlets: { id: number; name: string }[];
    filters: Filters;
    currency: App.Data.Property.CurrencyData | null;
};

const column = dataTableColumns<Row>();

export default function PosOrdersIndex({
    orders,
    summary,
    outlets,
    filters,
    currency,
}: Props) {
    const currencyCode = currency?.code ?? 'MYR';
    const businessDay = usePage().props.businessDate?.date;

    const columns = useMemo(
        () => [
            column.accessor('orderNumber', {
                header: 'Receipt',
                enableSorting: false,
                cell: (info) => (
                    <span className="font-medium group-hover/row:underline">
                        {info.getValue() ?? 'No number'}
                    </span>
                ),
            }),
            column.accessor('businessDate', {
                header: 'Date',
                enableSorting: false,
                cell: (info) => <DateText date={info.getValue()} />,
            }),
            column.accessor('outletName', {
                header: 'Outlet',
                enableSorting: false,
            }),
            column.accessor('orderType', {
                header: 'Type',
                enableSorting: false,
                cell: (info) =>
                    info.getValue() === 'refund' ? (
                        <Badge variant="outline">
                            <Undo2 aria-hidden />
                            Refund
                        </Badge>
                    ) : (
                        'Sale'
                    ),
            }),
            column.accessor('statusLabel', {
                header: 'Status',
                enableSorting: false,
                cell: (info) => (
                    <StatusBadge
                        status={{
                            value: info.row.original.status,
                            label: info.getValue(),
                            tone:
                                info.row.original.status === 'voided'
                                    ? 'danger'
                                    : 'success',
                        }}
                    />
                ),
            }),
            column.accessor('grandTotal', {
                header: `Total (${currencyCode})`,
                enableSorting: false,
                cell: (info) => (
                    <span className="font-medium">
                        {info.row.original.orderType === 'refund' && '−'}
                        <MoneyText
                            amount={info.getValue()}
                            currency={currency}
                            plain
                        />
                    </span>
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
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="POS receipts"
                    description="Settled and voided POS sales and refunds."
                />
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        icon={Wallet}
                        label="Net sales"
                        value={
                            <MoneyText
                                amount={summary.netSales}
                                currency={currency}
                            />
                        }
                        hint="Settled sales less refunds"
                    />
                    <StatCard
                        icon={ShoppingBag}
                        label="Sales receipts"
                        value={summary.sales}
                        hint="Settled"
                    />
                    <StatCard
                        icon={Undo2}
                        label="Refunds"
                        value={summary.refunds}
                        hint={
                            <>
                                <MoneyText
                                    amount={summary.refundTotal}
                                    currency={currency}
                                />{' '}
                                returned
                            </>
                        }
                    />
                    <StatCard
                        icon={Ban}
                        label="Voided"
                        value={summary.voided}
                        hint="Not counted in sales"
                    />
                </div>
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
                    <DateRangePicker
                        from={filters.from ?? ''}
                        to={filters.to ?? ''}
                        onChange={(from, to) => visit({ from, to })}
                        onClear={() => visit({ from: '', to: '' })}
                        presets={
                            businessDay
                                ? reportDatePresets(businessDay)
                                : undefined
                        }
                        months={2}
                        weekStartsOn={1}
                        placeholder="All dates"
                        className="w-auto"
                    />
                    <InputGroup className="w-56 sm:ml-auto">
                        <InputGroupAddon>
                            <Search aria-hidden />
                        </InputGroupAddon>
                        <InputGroupInput
                            type="search"
                            aria-label="Receipt number"
                            placeholder="Receipt no. + Enter"
                            defaultValue={filters.q ?? ''}
                            onKeyDown={(event) =>
                                event.key === 'Enter' &&
                                visit({ q: event.currentTarget.value })
                            }
                        />
                    </InputGroup>
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
