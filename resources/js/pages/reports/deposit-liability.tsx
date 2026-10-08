import { Shield } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type {
    ReportCells,
    ReportTotalCells,
} from '@/components/pms/reports/report-screen';
import { StatusBadge } from '@/components/pms/status-badge';
import { depositLiability } from '@/routes/reports';

type Row = App.Data.Reports.DepositLiabilityRow;
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
    currency: App.Enums.Currency | null;
};

const cells: ReportCells<Row> = {
    folio: (row) => <span className="font-medium">{row.folio}</span>,
    arrival: (row) => <DateText date={row.arrival} />,
    departure: (row) => <DateText date={row.departure} />,
    status: (row) => <StatusBadge status={row.status} />,
    received: (row, currency) => (
        <MoneyText amount={row.received} currency={currency} />
    ),
    refunded: (row, currency) => (
        <MoneyText amount={row.refunded} currency={currency} />
    ),
    charged: (row, currency) => (
        <MoneyText amount={row.charged} currency={currency} />
    ),
    deposit: (row, currency) => (
        <MoneyText amount={row.deposit} currency={currency} />
    ),
    held: (row, currency) => (
        <MoneyText amount={row.held} currency={currency} />
    ),
};

const totalCells: ReportTotalCells = {
    received: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
    refunded: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
    charged: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
    deposit: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
    held: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
};

export default function DepositLiabilityReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="deposit-liability"
            title="Deposit liability"
            description="The folios in credit on the date you pick: advances, overpayments and security deposits held. Status is today's status."
            emptyIcon={Shield}
            emptyTitle="No folio is in credit on this date"
            emptyDescription="Pick another date to see the money you hold for guests."
            cells={cells}
            totalCells={totalCells}
        />
    );
}

DepositLiabilityReport.layout = {
    breadcrumbs: [{ title: 'Deposit liability', href: depositLiability() }],
};
