import { CalendarCheck } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type { ReportCells } from '@/components/pms/reports/report-screen';
import { StatusBadge } from '@/components/pms/status-badge';
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
    currency: App.Data.Property.CurrencyData | null;
};

const cells: ReportCells<Row> = {
    reservation: (row) => (
        <span className="font-medium">{row.reservation}</span>
    ),
    arrival: (row) => <DateText date={row.arrival} />,
    status: (row) => <StatusBadge status={row.status} />,
    balance: (row, currency) => (
        <MoneyText amount={row.balance} currency={currency} />
    ),
};

export default function ArrivalsReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="arrivals"
            title="Arrivals"
            description="The units that arrive in the dates you pick. Cancelled and no-show units are left out."
            emptyIcon={CalendarCheck}
            emptyTitle="No arrivals in these dates"
            emptyDescription="Pick other dates to see the units that arrive."
            cells={cells}
        />
    );
}

ArrivalsReport.layout = {
    breadcrumbs: [{ title: 'Arrivals', href: arrivals() }],
};
