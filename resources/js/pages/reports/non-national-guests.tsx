import { Globe } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type {
    ReportCells,
    ReportTotalCells,
} from '@/components/pms/reports/report-screen';
import { nonNationalGuests } from '@/routes/reports';

type Row = App.Data.Reports.NonNationalGuestRow;
type Layout = App.Data.Reports.ReportLayoutData;

type Props = {
    rows: Illuminate.LengthAwarePaginator<number, Row>;
    totals: App.Data.Reports.ReportTotals;
    note: string | null;
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
    departure: (row) => <DateText date={row.departure} />,
    tourism_tax: (row, currency) => (
        <MoneyText amount={row.tourism_tax} currency={currency} />
    ),
};

const totalCells: ReportTotalCells = {
    tourism_tax: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
};

export default function NonNationalGuestsReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="non-national-guests"
            title="Non-national guests"
            description="The units with a foreign nationality that stayed in the dates you pick, with the tourism tax posted in those dates. ID numbers are masked unless you can view guest documents."
            emptyIcon={Globe}
            emptyTitle="No foreign guests in these dates"
            emptyDescription="Pick other dates to see the units with a foreign nationality."
            cells={cells}
            totalCells={totalCells}
        />
    );
}

NonNationalGuestsReport.layout = {
    breadcrumbs: [{ title: 'Non-national guests', href: nonNationalGuests() }],
};
