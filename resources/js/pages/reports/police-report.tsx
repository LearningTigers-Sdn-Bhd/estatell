import { ShieldCheck } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type { ReportCells } from '@/components/pms/reports/report-screen';
import { StatusBadge } from '@/components/pms/status-badge';
import { policeReport } from '@/routes/reports';

type Row = App.Data.Reports.PoliceReportRow;
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
    foreignOnly: boolean;
    currency: App.Data.Property.CurrencyData | null;
};

const cells: ReportCells<Row> = {
    guest: (row) => <span className="font-medium">{row.guest}</span>,
    date_of_birth: (row) => <DateText date={row.date_of_birth} />,
    arrival: (row) => <DateText date={row.arrival} />,
    departure: (row) => <DateText date={row.departure} />,
    status: (row) => <StatusBadge status={row.status} />,
};

export default function PoliceReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="police-report"
            title="Police report"
            description="Every guest of the units that stayed in the dates you pick, of any nationality. ID numbers are masked unless you can view guest documents."
            emptyIcon={ShieldCheck}
            emptyTitle="No guests stayed in these dates"
            emptyDescription="Pick other dates to see the guests who checked in."
            cells={cells}
        />
    );
}

PoliceReport.layout = {
    breadcrumbs: [{ title: 'Police report', href: policeReport() }],
};
