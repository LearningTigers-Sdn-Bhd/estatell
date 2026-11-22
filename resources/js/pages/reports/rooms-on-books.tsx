import { Head, router, usePage } from '@inertiajs/react';
import { CalendarRange, History, LayoutDashboard } from 'lucide-react';
import { useMemo } from 'react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { DateText, formatDate } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type {
    ReportCells,
    ReportTotalCells,
} from '@/components/pms/reports/report-screen';
import { StatusBadge } from '@/components/pms/status-badge';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { reportDatePresets } from '@/lib/date-presets';
import { roomsOnBooks } from '@/routes/reports';

type Currency = App.Data.Property.CurrencyData | null;
type Layout = App.Data.Reports.ReportLayoutData;
type GridRow = App.Data.Reports.RoomsOnBooksRow;
type ChangeRow = App.Data.Reports.BookingChangeRow;
type Overview = App.Data.Reports.RoomsOnBooksOverviewData;

type Tab = { value: string; label: string; href: string };

type Props = {
    tab: string;
    tabs: Tab[];
    from: string;
    to: string;
    maxRangeDays: number | null;
    presets: App.Enums.ReportDatePreset[] | null;
    currency: Currency;
    overview?: Overview;
    rows?: Illuminate.LengthAwarePaginator<number, GridRow | ChangeRow>;
    totals?: App.Data.Reports.ReportTotals | null;
    sections?: App.Data.Reports.ReportSection[] | null;
    sort?: string;
    layout?: Layout;
    canEditLayout?: boolean;
    orientationOptions?: EnumOption[];
};

const icons = {
    overview: LayoutDashboard,
    'on-the-books': CalendarRange,
    changes: History,
} as const;

/** A table cell amount: plain, because the column header carries the currency. */
const money = (amount: string, currency: Currency) => (
    <MoneyText amount={amount} currency={currency} plain />
);

const moneyTotal = (figure: string | number, currency: Currency) =>
    money(String(figure), currency);

const percent = (value: string) => (
    <span className="tabular-nums">{Number(value).toFixed(2)}%</span>
);

/** A change with its sign: "+3" or "-120.00". */
const signed = (value: string | number, decimals: number) => {
    const number = Number(value);

    return (
        <span className="tabular-nums">
            {number > 0 ? '+' : ''}
            {number.toLocaleString('en-GB', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            })}
        </span>
    );
};

const gridCells: ReportCells<GridRow> = {
    date: (row) => <DateText date={row.date} />,
    occupancy: (row) => percent(row.occupancy),
    arr: (row, currency) => money(row.arr, currency),
    room_revenue: (row, currency) => money(row.room_revenue, currency),
};

const gridTotalCells: ReportTotalCells = {
    occupancy: (figure) => percent(String(figure)),
    arr: moneyTotal,
    room_revenue: moneyTotal,
};

const changeCells: ReportCells<ChangeRow> = {
    kind: (row) => <StatusBadge status={row.kind} />,
    changed_at: (row) => (
        <span className="tabular-nums">
            {formatDate(row.changed_at.slice(0, 10))} {row.changed_at.slice(11)}
        </span>
    ),
    guest: (row) => <span className="font-medium">{row.guest}</span>,
    previous_arrival: (row) => <DateText date={row.previous_arrival} />,
    previous_departure: (row) => <DateText date={row.previous_departure} />,
    arrival: (row) => <DateText date={row.arrival} />,
    departure: (row) => <DateText date={row.departure} />,
    rate: (row, currency) => money(row.rate, currency),
    revenue: (row, currency) => money(row.revenue, currency),
    nights_change: (row) => signed(row.nights_change, 0),
    revenue_change: (row) => signed(row.revenue_change, 2),
    rate_plan: (row) =>
        row.complimentary ? `${row.rate_plan} (Complimentary)` : row.rate_plan,
};

const changeTotalCells: ReportTotalCells = {
    nights_change: (figure) => signed(figure, 0),
    revenue_change: (figure) => signed(figure, 2),
};

function OverviewTable({
    title,
    headings,
    rows,
    emptyText = 'Nothing to show.',
}: {
    title: string;
    emptyText?: string;
    headings: string[];
    rows: { key: string; cells: React.ReactNode[]; strong?: boolean }[];
}) {
    return (
        <Card size="sm">
            <CardHeader>
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{emptyText}</p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {headings.map((heading, index) => (
                                    <TableHead
                                        key={heading}
                                        className={
                                            index === 0
                                                ? undefined
                                                : 'text-right'
                                        }
                                    >
                                        {heading}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <TableRow
                                    key={row.key}
                                    className={
                                        row.strong ? 'font-medium' : undefined
                                    }
                                >
                                    {row.cells.map((cell, index) => (
                                        <TableCell
                                            key={index}
                                            className={
                                                index === 0
                                                    ? undefined
                                                    : 'text-right tabular-nums'
                                            }
                                        >
                                            {cell}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

function OverviewTab({
    overview,
    from,
    to,
    currency,
}: {
    overview: Overview;
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
                <span className="text-sm text-muted-foreground">
                    Changes made in these dates. Pace and the last 10 days count
                    from {formatDate(overview.asOf)}.
                </span>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <OverviewTable
                    title="Impact of changes"
                    headings={[
                        'Change',
                        'Rooms',
                        'Room nights',
                        'Revenue',
                        'ARR',
                    ]}
                    rows={overview.impact.map((line) => ({
                        key: line.kind ?? 'net',
                        strong: line.kind === null,
                        cells: [
                            line.label,
                            line.rooms,
                            signed(line.nights, 0),
                            signed(line.revenue, 2),
                            money(line.arr, currency),
                        ],
                    }))}
                />
                <OverviewTable
                    title="Pace: rooms per day on the books"
                    headings={['Window', 'Room nights', 'RPD', 'Occupancy']}
                    rows={overview.pace.map((line) => ({
                        key: line.label,
                        cells: [
                            line.label,
                            line.nights,
                            line.rpd,
                            percent(line.occupancy),
                        ],
                    }))}
                />
                <OverviewTable
                    title="Last 10 days"
                    emptyText="No closed dates yet. They start on the first night audit."
                    headings={['Date', 'Rooms sold', 'Occupancy']}
                    rows={overview.recent.map((day) => ({
                        key: day.date,
                        cells: [
                            <DateText key="date" date={day.date} />,
                            day.sold,
                            percent(day.occupancy),
                        ],
                    }))}
                />
                <OverviewTable
                    title="Pick-up by length of stay"
                    headings={[
                        'Length',
                        'Rooms',
                        'Room nights',
                        'Rate',
                        'Revenue',
                    ]}
                    rows={overview.lengthOfStay.map((band) => ({
                        key: band.label,
                        cells: [
                            band.label,
                            band.rooms,
                            band.nights,
                            money(band.rate, currency),
                            money(band.revenue, currency),
                        ],
                    }))}
                />
            </div>
        </div>
    );
}

/** The report tabs, for the tab bar and the breadcrumbs. */
function roomsOnBooksTabs(tabs: Tab[]): TabLink[] {
    return tabs.map((item) => ({
        ...item,
        icon: icons[item.value as keyof typeof icons],
    }));
}

export default function RoomsOnBooks({ tab, tabs, ...props }: Props) {
    const label = tabs.find((item) => item.value === tab)?.label ?? 'Overview';
    const shared = {
        from: props.from,
        to: props.to,
        maxRangeDays: props.maxRangeDays,
        presets: props.presets,
        currency: props.currency,
        totals: props.totals,
        sections: props.sections,
        sort: props.sort ?? '',
        layout: props.layout as Layout,
        canEditLayout: props.canEditLayout ?? false,
        orientationOptions: props.orientationOptions ?? [],
        embedded: true,
        query: { tab },
        title: label,
        layoutLabel: `Rooms on books › ${label}`,
    };

    return (
        <>
            <Head title="Rooms on books" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Rooms on books"
                    description="What is on the books for the coming days, and what was booked, cancelled, changed or missed."
                />

                <TabLinks
                    activeTab={tab}
                    panelWidth="full"
                    className="flex-1 gap-4"
                    panelClassName="flex flex-1 flex-col"
                    tabs={roomsOnBooksTabs(tabs)}
                >
                    {tab === 'overview' && props.overview ? (
                        <OverviewTab
                            overview={props.overview}
                            from={props.from}
                            to={props.to}
                            currency={props.currency}
                        />
                    ) : (
                        props.rows &&
                        props.layout &&
                        (tab === 'changes' ? (
                            <ReportScreen
                                key={tab}
                                {...shared}
                                rows={
                                    props.rows as Illuminate.LengthAwarePaginator<
                                        number,
                                        ChangeRow
                                    >
                                }
                                report="booking-changes"
                                description="Every room or bed booked, cancelled, moved to other dates or marked as a no-show in the dates, with the room nights and revenue it added or took away."
                                emptyIcon={History}
                                emptyTitle="No changes in these dates"
                                emptyDescription="Pick another range to see bookings, cancellations, amendments and no-shows."
                                cells={changeCells}
                                totalCells={changeTotalCells}
                            />
                        ) : (
                            <ReportScreen
                                key={tab}
                                {...shared}
                                rows={
                                    props.rows as Illuminate.LengthAwarePaginator<
                                        number,
                                        GridRow
                                    >
                                }
                                report="on-the-books"
                                description="For each date: the rooms full that morning, arrivals, departures and group arrivals, the rooms booked for the night, occupancy and the average booked rate."
                                emptyIcon={CalendarRange}
                                emptyTitle="No dates"
                                emptyDescription="Pick a range to see the rooms on the books."
                                cells={gridCells}
                                totalCells={gridTotalCells}
                            />
                        ))
                    )}
                </TabLinks>
            </div>
        </>
    );
}

RoomsOnBooks.layout = (props: Pick<Props, 'tab' | 'tabs'>) => ({
    breadcrumbs: withTabCrumb(
        [{ title: 'Rooms on books', href: roomsOnBooks() }],
        roomsOnBooksTabs(props.tabs),
        props.tab,
    ),
});
