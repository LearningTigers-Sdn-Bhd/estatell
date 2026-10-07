import { Head, router, usePage } from '@inertiajs/react';
import { CalendarCheck, Settings2 } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { ReportExportMenu } from '@/components/pms/reports/report-export-menu';
import { ReportLayoutSheet } from '@/components/pms/reports/report-layout-sheet';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import { reportDatePresets } from '@/lib/date-presets';
import { arrivals } from '@/routes/reports';

type Row = App.Data.Reports.ArrivalRow;
type Layout = App.Data.Reports.ReportLayoutData;

type Props = {
    rows: Illuminate.LengthAwarePaginator<number, Row>;
    sort: string;
    layout: Layout;
    canEditLayout: boolean;
    orientationOptions: EnumOption[];
    from: string;
    to: string;
    maxRangeDays: number | null;
    currency: App.Enums.Currency | null;
};

const column = dataTableColumns<Row>();

const MAX_SELECTED = 500;

export default function ArrivalsReport({
    rows,
    sort,
    layout,
    canEditLayout,
    orientationOptions,
    from,
    to,
    maxRangeDays,
    currency,
}: Props) {
    const [draft, setDraft] = useState<Layout | null>(null);
    const [layoutOpen, setLayoutOpen] = useState(false);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const active = draft ?? layout;

    const visible = useMemo(
        () => active.columns.filter((item) => item.visible),
        [active],
    );

    const openLayout = () => {
        setDraft(layout);
        setLayoutOpen(true);
    };

    const closeLayout = () => {
        setLayoutOpen(false);
        setDraft(null);
    };

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

            if (next.from !== from || next.to !== to) {
                setSelected(new Set());
            }

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

    const changeSelection = (next: Set<string>) => {
        if (next.size > MAX_SELECTED) {
            toast.add({
                title: `You can select up to ${MAX_SELECTED} rows`,
                type: 'error',
            });

            return;
        }

        setSelected(next);
    };

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
                    <div className="ml-auto flex items-center gap-2">
                        {canEditLayout && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={openLayout}
                            >
                                <Settings2 data-icon="inline-start" />
                                Layout
                            </Button>
                        )}
                        <ReportExportMenu
                            report="arrivals"
                            from={from}
                            to={to}
                            sort={sort}
                            rowKeys={[...selected]}
                        />
                    </div>
                </div>

                <DataTable
                    columns={tableColumns}
                    data={rows.data}
                    getRowId={(row) => String(row.key)}
                    sort={sort}
                    onSortChange={(next) => visit({ sort: next })}
                    rightAlign={rightAlign}
                    selection={{ selected, onChange: changeSelection }}
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

                {selected.size > 0 && (
                    <div className="flex items-center gap-3 text-sm text-muted-foreground">
                        <span>
                            {selected.size} selected · Export uses the selection
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setSelected(new Set())}
                        >
                            Clear
                        </Button>
                    </div>
                )}
            </div>

            {canEditLayout && draft && (
                <ReportLayoutSheet
                    open={layoutOpen}
                    onOpenChange={(next) => {
                        if (next) {
                            setLayoutOpen(true);
                        } else {
                            closeLayout();
                        }
                    }}
                    report="arrivals"
                    reportLabel="Arrivals"
                    saved={layout}
                    draft={draft}
                    onDraftChange={setDraft}
                    orientations={orientationOptions}
                    from={from}
                    to={to}
                    onDone={closeLayout}
                />
            )}
        </>
    );
}

ArrivalsReport.layout = {
    breadcrumbs: [{ title: 'Arrivals', href: arrivals() }],
};
