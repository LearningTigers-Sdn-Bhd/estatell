import { ChartColumn } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type {
    ReportCells,
    ReportTotalCells,
} from '@/components/pms/reports/report-screen';
import { revenue } from '@/routes/reports';

type Row = App.Data.Reports.RevenueRow;
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
    group: App.Enums.ReportGroup;
    groupOptions: EnumOption[];
};

const cells: ReportCells<Row> = {
    period: (row) =>
        row.group === 'month' ? (
            <time dateTime={row.period} className="tabular-nums">
                {new Date(`${row.period}-01T00:00:00`).toLocaleDateString(
                    'en-GB',
                    { month: 'short', year: 'numeric' },
                )}
            </time>
        ) : (
            <DateText date={row.period} />
        ),
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

export default function RevenueReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="revenue"
            title="Revenue"
            description="Revenue by transaction code, live from the folio ledger by posting date. Payments are not revenue."
            emptyIcon={ChartColumn}
            emptyTitle="No revenue in these dates"
            emptyDescription="Pick another range to see revenue."
            cells={cells}
            totalCells={totalCells}
        />
    );
}

RevenueReport.layout = {
    breadcrumbs: [{ title: 'Revenue', href: revenue() }],
};
