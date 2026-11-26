import { Head, router } from '@inertiajs/react';
import {
    CircleCheck,
    CircleDot,
    Scale,
    Store,
    TriangleAlert,
} from 'lucide-react';
import { useMemo } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatCard } from '@/components/pms/stat-card';
import { StatusBadge } from '@/components/pms/status-badge';
import { index, show } from '@/routes/finance/pos-shifts';

type Row = App.Data.Pos.PosShiftData;

type Props = {
    shifts: {
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
        open: number;
        closed: number;
        mismatched: number;
        netVariance: string;
    };
    currency: App.Data.Property.CurrencyData | null;
};

function dateTime(value: string): string {
    return new Date(value).toLocaleString('en-GB', {
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
}

const column = dataTableColumns<Row>();

export default function PosShiftsIndex({ shifts, summary, currency }: Props) {
    const currencyCode = currency?.code ?? 'MYR';

    const columns = useMemo(
        () => [
            column.accessor('shiftNumber', {
                header: 'Shift',
                enableSorting: false,
                cell: (info) => (
                    <span className="font-medium group-hover/row:underline">
                        {info.getValue()}
                    </span>
                ),
            }),
            column.accessor('outletName', {
                header: 'Outlet',
                enableSorting: false,
            }),
            column.accessor('registerName', {
                header: 'Register',
                enableSorting: false,
            }),
            column.accessor('openedBy', {
                header: 'Opened by',
                enableSorting: false,
            }),
            column.accessor('openedAt', {
                header: 'Opened',
                enableSorting: false,
                cell: (info) => (
                    <span className="tabular-nums">
                        {dateTime(info.getValue())}
                    </span>
                ),
            }),
            column.accessor('closedAt', {
                header: 'Closed',
                enableSorting: false,
                cell: (info) => {
                    const value = info.getValue();

                    return value === null ? (
                        <span className="text-muted-foreground">—</span>
                    ) : (
                        <span className="tabular-nums">{dateTime(value)}</span>
                    );
                },
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
                                info.row.original.status === 'open'
                                    ? 'success'
                                    : info.row.original.status === 'closed'
                                      ? 'neutral'
                                      : 'warning',
                        }}
                    />
                ),
            }),
            column.accessor('cashVariance', {
                header: `Cash variance (${currencyCode})`,
                enableSorting: false,
                cell: (info) =>
                    info.getValue() === null ? (
                        <span className="text-muted-foreground">Open</span>
                    ) : (
                        <span
                            className={
                                Number(info.getValue()) !== 0
                                    ? 'font-medium text-destructive'
                                    : undefined
                            }
                        >
                            <MoneyText
                                amount={info.getValue() ?? '0'}
                                currency={currency}
                                plain
                            />
                            {Number(info.getValue()) !== 0 &&
                                (Number(info.getValue()) < 0
                                    ? ' short'
                                    : ' over')}
                        </span>
                    ),
            }),
        ],
        [currency, currencyCode],
    );

    return (
        <>
            <Head title="POS shifts" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="POS shifts"
                    description="Register shifts with the cash variance counted at close."
                />
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        icon={CircleDot}
                        label="Open shifts"
                        value={summary.open}
                        hint="Including those counting cash"
                    />
                    <StatCard
                        icon={CircleCheck}
                        label="Closed shifts"
                        value={summary.closed}
                        hint="All time"
                    />
                    <StatCard
                        icon={TriangleAlert}
                        label="Cash mismatches"
                        value={summary.mismatched}
                        hint="Closed shifts short or over"
                    />
                    <StatCard
                        icon={Scale}
                        label="Net cash variance"
                        value={
                            <MoneyText
                                amount={summary.netVariance}
                                currency={currency}
                            />
                        }
                        hint={
                            Number(summary.netVariance) < 0
                                ? 'Short across closed shifts'
                                : Number(summary.netVariance) > 0
                                  ? 'Over across closed shifts'
                                  : 'Balanced across closed shifts'
                        }
                    />
                </div>
                <DataTable
                    columns={columns}
                    data={shifts.data}
                    getRowId={(row) => String(row.id)}
                    sort=""
                    onSortChange={() => undefined}
                    onRowClick={(row) =>
                        router.visit(show.url({ shift: row.id }))
                    }
                    pagination={{
                        currentPage: shifts.meta.current_page,
                        lastPage: shifts.meta.last_page,
                        from: shifts.meta.from,
                        to: shifts.meta.to,
                        total: shifts.meta.total,
                        prevUrl: shifts.meta.prev_page_url,
                        nextUrl: shifts.meta.next_page_url,
                    }}
                    empty={
                        <EmptyState
                            icon={Store}
                            title="No shifts yet"
                            description="A shift appears when a register opens."
                        />
                    }
                />
            </div>
        </>
    );
}

PosShiftsIndex.layout = {
    breadcrumbs: [{ title: 'POS shifts', href: index() }],
};
