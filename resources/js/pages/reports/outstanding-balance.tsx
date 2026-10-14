import { Wallet } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type {
    ReportCells,
    ReportTotalCells,
} from '@/components/pms/reports/report-screen';
import { StatusBadge } from '@/components/pms/status-badge';
import { outstandingBalance } from '@/routes/reports';

type Row = App.Data.Reports.OutstandingBalanceRow;
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
    folio: (row) => <span className="font-medium">{row.folio}</span>,
    arrival: (row) => <DateText date={row.arrival} />,
    departure: (row) => <DateText date={row.departure} />,
    status: (row) => <StatusBadge status={row.status} />,
    charges: (row, currency) => (
        <MoneyText amount={row.charges} currency={currency} />
    ),
    payments: (row, currency) => (
        <MoneyText amount={row.payments} currency={currency} />
    ),
    balance: (row, currency) => (
        <MoneyText amount={row.balance} currency={currency} />
    ),
};

const totalCells: ReportTotalCells = {
    charges: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
    payments: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
    balance: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
};

export default function OutstandingBalanceReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="outstanding-balance"
            title="Outstanding balance"
            description="The folios that owe money on the date you pick, from the ledger up to that date. Status is today's status."
            emptyIcon={Wallet}
            emptyTitle="No folio owes money on this date"
            emptyDescription="Pick another date to see the folios with a balance to collect."
            cells={cells}
            totalCells={totalCells}
        />
    );
}

OutstandingBalanceReport.layout = {
    breadcrumbs: [{ title: 'Outstanding balance', href: outstandingBalance() }],
};
