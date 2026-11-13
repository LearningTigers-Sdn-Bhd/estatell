import { Head } from '@inertiajs/react';
import {
    Activity,
    Bed,
    CalendarDays,
    CircleDot,
    Globe,
    Tag,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { BookingSourceBadge } from '@/components/pms/booking-source-badge';
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
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { bookingPerformance } from '@/routes/reports';

type Row = App.Data.Reports.BookingPerformanceRow;
type Layout = App.Data.Reports.ReportLayoutData;

type Props = {
    rows: Illuminate.LengthAwarePaginator<number, Row>;
    totals: App.Data.Reports.ReportTotals;
    sort: string;
    layout: Layout;
    canEditLayout: boolean;
    orientationOptions: EnumOption[];
    from: string;
    to: string;
    maxRangeDays: number | null;
    currency: App.Data.Property.CurrencyData | null;
    group: App.Enums.ReportGroup;
    groupOptions: EnumOption[];
};

const cells: ReportCells<Row> = {
    group: (row) => {
        if (row.grouping === 'day') {
            return <ReportPeriod period={row.group} group="day" />;
        }

        if (row.status) {
            return <StatusBadge status={row.status} />;
        }

        return (
            <span className="flex items-center gap-2 font-medium">
                {row.source && (
                    <BookingSourceBadge
                        source={row.source}
                        size="sm"
                        tooltip={false}
                    />
                )}
                {row.group}
            </span>
        );
    },
    revenue: (row, currency) => (
        <MoneyText amount={row.revenue} currency={currency} plain />
    ),
    adr: (row, currency) =>
        row.adr === null ? (
            '—'
        ) : (
            <MoneyText amount={row.adr} currency={currency} plain />
        ),
};

const totalCells: ReportTotalCells = {
    revenue: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} plain />
    ),
    adr: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} plain />
    ),
};

const icons: Partial<Record<App.Enums.ReportGroup, LucideIcon>> = {
    room_type: Bed,
    source: Globe,
    rate_plan: Tag,
    status: CircleDot,
    day: CalendarDays,
};

/** One tab per group, keeping the dates. The Day group is the booking date on this report. */
function groupTabs(
    groupOptions: EnumOption[],
    from: string,
    to: string,
): TabLink[] {
    return groupOptions.map((option) => ({
        value: option.value,
        label: option.value === 'day' ? 'Booking date' : option.label,
        icon: icons[option.value as App.Enums.ReportGroup] ?? Activity,
        href: bookingPerformance(undefined, {
            query: { group: option.value, from, to },
        }),
    }));
}

export default function BookingPerformanceReport({
    group,
    groupOptions,
    ...props
}: Props) {
    const tabs = groupTabs(groupOptions, props.from, props.to);
    const label = tabs.find((tab) => tab.value === group)?.label as string;

    return (
        <>
            <Head title="Booking performance" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Booking performance"
                    description="Bookings made in these dates, with their room nights, room revenue, ADR and lead time. A booking with two room types counts in each row and once in the total."
                />

                <TabLinks
                    activeTab={group}
                    panelWidth="full"
                    className="flex-1 gap-4"
                    panelClassName="flex flex-1 flex-col"
                    tabs={tabs}
                >
                    <ReportScreen
                        {...props}
                        embedded
                        group={group}
                        report="booking-performance"
                        title="Booking performance"
                        description=""
                        layoutLabel="Booking performance"
                        columnLabels={{ group: label }}
                        emptyIcon={Activity}
                        emptyTitle="No bookings made in these dates"
                        emptyDescription="Pick another range to see booking performance."
                        cells={cells}
                        totalCells={totalCells}
                    />
                </TabLinks>
            </div>
        </>
    );
}

BookingPerformanceReport.layout = (
    props: Pick<Props, 'group' | 'groupOptions' | 'from' | 'to'>,
) => ({
    breadcrumbs: withTabCrumb(
        [{ title: 'Booking performance', href: bookingPerformance() }],
        groupTabs(props.groupOptions, props.from, props.to),
        props.group,
    ),
});
