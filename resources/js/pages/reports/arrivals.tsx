import { Head, router, usePage } from '@inertiajs/react';
import { CalendarCheck } from 'lucide-react';
import { useCallback, useMemo } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { reportDatePresets } from '@/lib/date-presets';
import { arrivals } from '@/routes/reports';

type Row = App.Data.Reports.ArrivalRow;
type Column = App.Data.Reports.ReportColumn;

type Props = {
    rows: Illuminate.LengthAwarePaginator<number, Row>;
    sort: string;
    columns: Column[];
    from: string;
    to: string;
    maxRangeDays: number | null;
    currency: App.Enums.Currency | null;
};

const column = dataTableColumns<Row>();

export default function ArrivalsReport({
    rows,
    sort,
    columns,
    from,
    to,
    maxRangeDays,
    currency,
}: Props) {
    const visible = useMemo(
        () => columns.filter((item) => item.defaultVisible),
        [columns],
    );

    const tableColumns = useMemo(
        () =>
            visible.map((item) =>
                column.accessor(
                    (row) => {
                        const value = row[item.key as keyof Row];

                        return typeof value === 'object' && value !== null
                            ? value.label
                            : value;
                    },
                    {
                        id: item.key,
                        header: item.label,
                        cell: (info) => {
                            const row = info.row.original;

                            switch (item.key) {
                                case 'reservation':
                                    return (
                                        <span className="font-medium">
                                            {row.reservation}
                                        </span>
                                    );
                                case 'arrival':
                                    return <DateText date={row.arrival} />;
                                case 'status':
                                    return <StatusBadge status={row.status} />;
                                case 'balance':
                                    return (
                                        <MoneyText
                                            amount={row.balance}
                                            currency={currency}
                                        />
                                    );
                                default:
                                    return (
                                        info.getValue() ?? (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )
                                    );
                            }
                        },
                    },
                ),
            ),
        [visible, currency],
    );

    const rightAlign = useMemo(
        () =>
            visible
                .filter((item) => item.align === 'right')
                .map((item) => item.key),
        [visible],
    );

    const { businessDate } = usePage().props;
    const businessDay = businessDate?.date;
    const presets = useMemo(
        () => (businessDay ? reportDatePresets(businessDay) : []),
        [businessDay],
    );

    const visit = useCallback(
        (changes: { from?: string; to?: string; sort?: string }) => {
            const next = { from, to, sort, ...changes };

            router.get(
                arrivals.url(undefined, {
                    query: {
                        from: next.from,
                        to: next.to,
                        ...(next.sort ? { sort: next.sort } : {}),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [from, to, sort],
    );

    return (
        <>
            <Head title="Arrivals" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Arrivals"
                    description="The units that arrive in the dates you pick. Cancelled and no-show units are left out."
                />

                <div className="flex flex-wrap items-center gap-2">
                    <DateRangePicker
                        from={from}
                        to={to}
                        onChange={(nextFrom, nextTo) =>
                            visit({ from: nextFrom, to: nextTo })
                        }
                        presets={presets}
                        maxDays={maxRangeDays ?? undefined}
                        weekStartsOn={1}
                        className="w-auto"
                    />
                </div>

                <DataTable
                    columns={tableColumns}
                    data={rows.data}
                    getRowId={(row) => String(row.key)}
                    sort={sort}
                    onSortChange={(next) => visit({ sort: next })}
                    rightAlign={rightAlign}
                    pagination={{
                        currentPage: rows.meta.current_page,
                        lastPage: rows.meta.last_page,
                        from: rows.meta.from,
                        to: rows.meta.to,
                        total: rows.meta.total,
                        prevUrl: rows.meta.prev_page_url,
                        nextUrl: rows.meta.next_page_url,
                    }}
                    empty={
                        <EmptyState
                            icon={CalendarCheck}
                            title="No arrivals in these dates"
                            description="Pick other dates to see the units that arrive."
                        />
                    }
                />
            </div>
        </>
    );
}

ArrivalsReport.layout = {
    breadcrumbs: [{ title: 'Arrivals', href: arrivals() }],
};
