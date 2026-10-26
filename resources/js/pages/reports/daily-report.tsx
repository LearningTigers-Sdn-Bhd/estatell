import { Head, router, usePage } from '@inertiajs/react';
import { ChartColumn, LayoutDashboard, Wallet } from 'lucide-react';
import { useMemo } from 'react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { ReportPeriod } from '@/components/pms/reports/report-period';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type {
    ReportCells,
    ReportTotalCells,
} from '@/components/pms/reports/report-screen';
import { StatusBadge } from '@/components/pms/status-badge';
import { TabLinks } from '@/components/pms/tab-links';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { reportDatePresets } from '@/lib/date-presets';
import { dailyReport } from '@/routes/reports';

type Currency = App.Data.Property.CurrencyData | null;
type Layout = App.Data.Reports.ReportLayoutData;
type RevenueRow = App.Data.Reports.DailyRevenueReportRow;
type PaymentRow = App.Data.Reports.PaymentRow;

type Tab = { value: string; label: string; href: string };

type Props = {
    tab: string;
    tabs: Tab[];
    from: string;
    to: string;
    maxRangeDays: number | null;
    currency: Currency;
    overview?: App.Data.Reports.DailyOverviewData;
    rows?: Illuminate.LengthAwarePaginator<number, RevenueRow | PaymentRow>;
    totals?: App.Data.Reports.ReportTotals | null;
    breakdown?: App.Data.Reports.ReportBreakdown | null;
    sort?: string;
    layout?: Layout;
    canEditLayout?: boolean;
    orientationOptions?: EnumOption[];
    group?: App.Enums.ReportGroup;
    groupOptions?: EnumOption[];
    staff?: string;
    staffOptions?: EnumOption[];
    refundsOnly?: boolean;
};

const icons = {
    overview: LayoutDashboard,
    revenue: ChartColumn,
    cashier: Wallet,
} as const;

const money = (amount: string, currency: Currency) => (
    <MoneyText amount={amount} currency={currency} />
);

const paymentCells: ReportCells<PaymentRow> = {
    date: (row) => <DateText date={row.date} />,
    payer: (row) => <span className="font-medium">{row.payer}</span>,
    status: (row) => <StatusBadge status={row.status} />,
    amount: (row, currency) => money(row.amount, currency),
};

const paymentTotalCells: ReportTotalCells = {
    amount: (figure, currency) => money(String(figure), currency),
};

/** The cells of the revenue table. The tax columns have a dynamic key, so they come from the layout. */
function revenueCells(layout: Layout): {
    cells: ReportCells<RevenueRow>;
    totalCells: ReportTotalCells;
} {
    const cells: ReportCells<RevenueRow> = {
        period: (row) => <ReportPeriod period={row.period} group={row.group} />,
    };
    const totalCells: ReportTotalCells = {};

    for (const column of layout.columns) {
        if (column.key === 'period' || column.key === 'bookings') {
            continue;
        }

        const code = column.key.startsWith('tax:') ? column.key.slice(4) : null;

        cells[column.key] = (row, currency) =>
            money(
                code === null
                    ? (row as unknown as Record<string, string>)[column.key]
                    : (row.taxes[code] ?? '0.00'),
                currency,
            );
        totalCells[column.key] = (figure, currency) =>
            money(String(figure), currency);
    }

    return { cells, totalCells };
}

function Overview({
    overview,
    from,
    to,
    currency,
}: {
    overview: App.Data.Reports.DailyOverviewData;
    from: string;
    to: string;
    currency: Currency;
}) {
    const { props } = usePage();
    const businessDay = props.businessDate?.date;
    const presets = useMemo(
        () => (businessDay ? reportDatePresets(businessDay) : []),
        [businessDay],
    );

    const sections: {
        title: string;
        cards: { label: string; value: string | number; isMoney: boolean }[];
    }[] = [
        {
            title: 'Revenue',
            cards: [
                {
                    label: 'Bookings with revenue',
                    value: overview.bookings,
                    isMoney: false,
                },
                {
                    label: 'Total charges',
                    value: overview.totalCharges,
                    isMoney: true,
                },
                {
                    label: 'Adjustments',
                    value: overview.adjustments,
                    isMoney: true,
                },
                {
                    label: 'Net revenue',
                    value: overview.netRevenue,
                    isMoney: true,
                },
            ],
        },
        {
            title: 'Cashier activity',
            cards: [
                {
                    label: 'Movements',
                    value: overview.movements,
                    isMoney: false,
                },
                {
                    label: 'Collected',
                    value: overview.collected,
                    isMoney: true,
                },
                { label: 'Refunded', value: overview.refunded, isMoney: true },
                {
                    label: 'Net at desk',
                    value: overview.netAtDesk,
                    isMoney: true,
                },
            ],
        },
    ];

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-2">
                <DateRangePicker
                    from={from}
                    to={to}
                    onChange={(nextFrom, nextTo) =>
                        router.get(
                            new URL(window.location.href).pathname,
                            { tab: 'overview', from: nextFrom, to: nextTo },
                            { preserveState: true, replace: true },
                        )
                    }
                    presets={presets}
                    maxDays={366}
                    weekStartsOn={1}
                    className="w-auto"
                />
            </div>

            {sections.map((section) => (
                <section key={section.title} className="flex flex-col gap-2">
                    <h2 className="text-sm font-medium">{section.title}</h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {section.cards.map((card) => (
                            <Card key={card.label} size="sm">
                                <CardHeader>
                                    <CardTitle className="text-sm font-normal text-muted-foreground">
                                        {card.label}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="text-2xl font-semibold tabular-nums">
                                    {card.isMoney
                                        ? money(String(card.value), currency)
                                        : card.value}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}

export default function DailyReport({ tab, tabs, ...props }: Props) {
    const revenue = useMemo(
        () => (props.layout ? revenueCells(props.layout) : null),
        [props.layout],
    );
    const label = tabs.find((item) => item.value === tab)?.label ?? 'Overview';
    const screen = {
        title: label,
        layoutLabel: `Daily report › ${label}`,
        ...(tab === 'cashier'
            ? {
                  report: 'payments' as const,
                  description:
                      'Payments and refunds by business date. Only completed payments count in the totals; refunds are negative.',
                  emptyIcon: Wallet,
                  emptyTitle: 'No payments in these dates',
                  emptyDescription: 'Pick another range to see payments.',
              }
            : {
                  report: 'daily-revenue' as const,
                  description:
                      'Revenue for each date, live from the folio ledger by posting date, with one column for each tax.',
                  emptyIcon: ChartColumn,
                  emptyTitle: 'No revenue in these dates',
                  emptyDescription: 'Pick another range to see revenue.',
              }),
    };

    return (
        <>
            <Head title="Daily report" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Daily report"
                    description="Revenue and cashier activity for the dates you pick."
                />

                <TabLinks
                    activeTab={tab}
                    panelWidth="full"
                    className="flex-1 gap-4"
                    panelClassName="flex flex-1 flex-col"
                    scrollable
                    tabs={tabs.map((item) => ({
                        ...item,
                        icon: icons[item.value as keyof typeof icons],
                    }))}
                >
                    {tab === 'overview' && props.overview ? (
                        <Overview
                            overview={props.overview}
                            from={props.from}
                            to={props.to}
                            currency={props.currency}
                        />
                    ) : (
                        props.rows &&
                        props.layout &&
                        props.sort !== undefined &&
                        (tab === 'cashier' ? (
                            <ReportScreen
                                key={tab}
                                {...(props as Required<
                                    Pick<
                                        Props,
                                        | 'layout'
                                        | 'sort'
                                        | 'canEditLayout'
                                        | 'orientationOptions'
                                    >
                                >)}
                                rows={
                                    props.rows as Illuminate.LengthAwarePaginator<
                                        number,
                                        PaymentRow
                                    >
                                }
                                from={props.from}
                                to={props.to}
                                maxRangeDays={props.maxRangeDays}
                                currency={props.currency}
                                totals={props.totals}
                                breakdown={props.breakdown}
                                group={props.group}
                                groupOptions={props.groupOptions}
                                staff={props.staff}
                                staffOptions={props.staffOptions}
                                refundsOnly={props.refundsOnly}
                                embedded
                                query={{ tab }}
                                cells={paymentCells}
                                totalCells={paymentTotalCells}
                                {...screen}
                            />
                        ) : (
                            <ReportScreen
                                key={tab}
                                {...(props as Required<
                                    Pick<
                                        Props,
                                        | 'layout'
                                        | 'sort'
                                        | 'canEditLayout'
                                        | 'orientationOptions'
                                    >
                                >)}
                                rows={
                                    props.rows as Illuminate.LengthAwarePaginator<
                                        number,
                                        RevenueRow
                                    >
                                }
                                from={props.from}
                                to={props.to}
                                maxRangeDays={props.maxRangeDays}
                                currency={props.currency}
                                totals={props.totals}
                                group={props.group}
                                groupOptions={props.groupOptions}
                                embedded
                                query={{ tab }}
                                cells={revenue?.cells}
                                totalCells={revenue?.totalCells}
                                {...screen}
                            />
                        ))
                    )}
                </TabLinks>
            </div>
        </>
    );
}

DailyReport.layout = {
    breadcrumbs: [{ title: 'Daily report', href: dailyReport() }],
};
