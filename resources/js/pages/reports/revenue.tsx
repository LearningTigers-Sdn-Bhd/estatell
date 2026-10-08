import { ChartColumn } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportCellLink } from '@/components/pms/reports/report-cell-link';
import { ReportPeriod } from '@/components/pms/reports/report-period';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type {
    ReportCells,
    ReportTotalCells,
} from '@/components/pms/reports/report-screen';
import { folioLedger, revenue } from '@/routes/reports';

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
    period: (row) => <ReportPeriod period={row.period} group={row.group} />,
    code: (row) => <span className="font-medium">{row.code}</span>,
    amount: (row, currency) => (
        <ReportCellLink
            href={folioLedger(undefined, {
                query: { code: row.code, from: row.from, to: row.to },
            })}
        >
            <MoneyText amount={row.amount} currency={currency} />
        </ReportCellLink>
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
