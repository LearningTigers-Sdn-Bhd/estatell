import { Head } from '@inertiajs/react';
import { CalendarCheck, DoorOpen, LogOut, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type { ReportCells } from '@/components/pms/reports/report-screen';
import { StatusBadge } from '@/components/pms/status-badge';
import { TabLinks } from '@/components/pms/tab-links';
import { guestList } from '@/routes/reports';

type Row =
    | App.Data.Reports.ArrivalRow
    | App.Data.Reports.InHouseRow
    | App.Data.Reports.DepartureRow
    | App.Data.Reports.CheckedOutRow;
type Layout = App.Data.Reports.ReportLayoutData;

type Tab = {
    value: string;
    label: string;
    count: number;
    href: string;
};

type Props = {
    tab: string;
    tabs: Tab[];
    rows: Illuminate.LengthAwarePaginator<number, Row>;
    totals: App.Data.Reports.ReportTotals | null;
    sort: string;
    layout: Layout;
    canEditLayout: boolean;
    orientationOptions: EnumOption[];
    from: string;
    to: string;
    maxRangeDays: number | null;
    currency: App.Data.Property.CurrencyData | null;
};

const reservation = (row: { reservation: string }) => (
    <span className="font-medium">{row.reservation}</span>
);

const balance = (
    row: { balance: string },
    currency: App.Data.Property.CurrencyData | null,
) => <MoneyText amount={row.balance} currency={currency} />;

const arrivalCells: ReportCells<App.Data.Reports.ArrivalRow> = {
    reservation,
    arrival: (row) => <DateText date={row.arrival} />,
    status: (row) => <StatusBadge status={row.status} />,
    balance,
};

const inHouseCells: ReportCells<App.Data.Reports.InHouseRow> = {
    reservation,
    arrival: (row) => <DateText date={row.arrival} />,
    departure: (row) => <DateText date={row.departure} />,
    balance,
};

const departureCells: ReportCells<App.Data.Reports.DepartureRow> = {
    reservation,
    departure: (row) => <DateText date={row.departure} />,
    status: (row) => <StatusBadge status={row.status} />,
    balance,
};

const checkedOutCells: ReportCells<App.Data.Reports.CheckedOutRow> = {
    reservation,
    departure: (row) => <DateText date={row.departure} />,
    checked_out_at: (row) =>
        row.checked_out_at ? (
            <span className="tabular-nums">
                <DateText date={row.checked_out_at.slice(0, 10)} />{' '}
                {row.checked_out_at.slice(11)}
            </span>
        ) : (
            <span className="text-muted-foreground">—</span>
        ),
    balance,
};

const screens: Record<
    string,
    {
        icon: LucideIcon;
        description: string;
        emptyTitle: string;
        emptyDescription: string;
        cells: ReportCells<Row>;
    }
> = {
    arrivals: {
        icon: CalendarCheck,
        description:
            'The units that arrive in the dates you pick. Cancelled and no-show units are left out.',
        emptyTitle: 'No arrivals in these dates',
        emptyDescription: 'Pick other dates to see the units that arrive.',
        cells: arrivalCells as unknown as ReportCells<Row>,
    },
    'in-house': {
        icon: Users,
        description:
            'The units that stay on the date you pick. The arrival day counts and the departure day does not.',
        emptyTitle: 'No units stay on this date',
        emptyDescription: 'Pick another date to see who stays.',
        cells: inHouseCells as unknown as ReportCells<Row>,
    },
    departures: {
        icon: DoorOpen,
        description:
            'The units that depart in the dates you pick. Cancelled and no-show units are left out.',
        emptyTitle: 'No departures in these dates',
        emptyDescription: 'Pick other dates to see the units that depart.',
        cells: departureCells as unknown as ReportCells<Row>,
    },
    'checked-out': {
        icon: LogOut,
        description:
            'The units that checked out in the dates you pick, with their balance.',
        emptyTitle: 'No check-outs in these dates',
        emptyDescription: 'Pick other dates to see the units that checked out.',
        cells: checkedOutCells as unknown as ReportCells<Row>,
    },
};

export default function GuestList({ tab, tabs, ...props }: Props) {
    const screen = screens[tab] ?? screens.arrivals;
    const label = tabs.find((item) => item.value === tab)?.label ?? 'Arrivals';

    return (
        <>
            <Head title="Guest list" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Guest list"
                    description="Arrivals, in-house guests, departures and check-outs for the dates you pick."
                />

                <TabLinks
                    activeTab={tab}
                    panelWidth="full"
                    className="flex-1 gap-4"
                    panelClassName="flex flex-1 flex-col"
                    scrollable
                    tabs={tabs.map((item) => ({
                        value: item.value,
                        label: `${item.label} (${item.count})`,
                        icon: screens[item.value].icon,
                        href: item.href,
                    }))}
                >
                    <ReportScreen
                        key={tab}
                        {...props}
                        embedded
                        query={{ tab }}
                        layoutLabel={`Guest list › ${label}`}
                        report={tab as App.Enums.ReportKey}
                        title={label}
                        description={screen.description}
                        emptyIcon={screen.icon}
                        emptyTitle={screen.emptyTitle}
                        emptyDescription={screen.emptyDescription}
                        cells={screen.cells}
                    />
                </TabLinks>
            </div>
        </>
    );
}

GuestList.layout = {
    breadcrumbs: [{ title: 'Guest list', href: guestList() }],
};
