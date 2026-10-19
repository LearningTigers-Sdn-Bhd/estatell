import { Receipt } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type {
    ReportCells,
    ReportTotalCells,
} from '@/components/pms/reports/report-screen';
import { folioLedger } from '@/routes/reports';

type Row = App.Data.Reports.FolioLedgerRow;
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
    code: string | null;
    codeOptions: EnumOption[];
};

const cells: ReportCells<Row> = {
    date: (row) => <DateText date={row.date} />,
    service_date: (row) => <DateText date={row.serviceDate} />,
    folio: (row) => <span className="font-medium">{row.folio}</span>,
    code: (row) => <span className="font-medium">{row.code}</span>,
    amount: (row, currency) => (
        <MoneyText amount={row.amount} currency={currency} />
    ),
};

const totalCells: ReportTotalCells = {
    amount: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
};

export default function FolioLedgerReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="folio-ledger"
            title="Folio ledger"
            description="Every folio line posted in the dates you pick, of any kind. Payments and reversals are negative."
            emptyIcon={Receipt}
            emptyTitle="No folio lines in these dates"
            emptyDescription="Pick other dates or another code to see folio lines."
            cells={cells}
            totalCells={totalCells}
        />
    );
}

FolioLedgerReport.layout = {
    breadcrumbs: [{ title: 'Folio ledger', href: folioLedger() }],
};
