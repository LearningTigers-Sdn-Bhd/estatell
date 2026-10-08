import { Users } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type { ReportCells } from '@/components/pms/reports/report-screen';
import { inHouse } from '@/routes/reports';

type Row = App.Data.Reports.InHouseRow;
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
    currency: App.Enums.Currency | null;
};

const cells: ReportCells<Row> = {
    reservation: (row) => (
        <span className="font-medium">{row.reservation}</span>
    ),
    arrival: (row) => <DateText date={row.arrival} />,
    departure: (row) => <DateText date={row.departure} />,
    balance: (row, currency) => (
        <MoneyText amount={row.balance} currency={currency} />
    ),
};

export default function InHouseReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="in-house"
            title="In-house"
            description="The units that stay on the date you pick. The arrival day counts and the departure day does not."
            emptyIcon={Users}
            emptyTitle="No units stay on this date"
            emptyDescription="Pick another date to see who stays."
            cells={cells}
        />
    );
}

InHouseReport.layout = {
    breadcrumbs: [{ title: 'In-house', href: inHouse() }],
};
