import { CreditCard } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type {
    ReportCells,
    ReportTotalCells,
} from '@/components/pms/reports/report-screen';
import { StatusBadge } from '@/components/pms/status-badge';
import { payments } from '@/routes/reports';

type Row = App.Data.Reports.PaymentRow;
type Layout = App.Data.Reports.ReportLayoutData;

type Props = {
    rows: Illuminate.LengthAwarePaginator<number, Row>;
    totals: App.Data.Reports.ReportTotals;
    breakdown: App.Data.Reports.ReportBreakdown;
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
    date: (row) => <DateText date={row.date} />,
    payer: (row) => <span className="font-medium">{row.payer}</span>,
    status: (row) => <StatusBadge status={row.status} />,
    amount: (row, currency) => (
        <MoneyText amount={row.amount} currency={currency} />
    ),
};

const totalCells: ReportTotalCells = {
    amount: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
};

export default function PaymentsReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="payments"
            title="Payments"
            description="Payments and refunds by business date, with city ledger payments. Only completed payments count in the totals; refunds are negative."
            emptyIcon={CreditCard}
            emptyTitle="No payments in these dates"
            emptyDescription="Pick another range to see payments."
            cells={cells}
            totalCells={totalCells}
        />
    );
}

PaymentsReport.layout = {
    breadcrumbs: [{ title: 'Payments', href: payments() }],
};
