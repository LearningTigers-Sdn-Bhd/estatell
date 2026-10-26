import { Landmark } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportPeriod } from '@/components/pms/reports/report-period';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type {
    ReportCells,
    ReportTotalCells,
} from '@/components/pms/reports/report-screen';
import { tax } from '@/routes/reports';

type Row = App.Data.Reports.TaxRow;
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
    currency: App.Data.Property.CurrencyData | null;
    group: App.Enums.ReportGroup;
    groupOptions: EnumOption[];
};

const cells: ReportCells<Row> = {
    period: (row) => <ReportPeriod period={row.period} group={row.group} />,
    tax: (row) => <span className="font-medium">{row.tax}</span>,
    base: (row, currency) =>
        row.base === null ? (
            <span className="text-muted-foreground">—</span>
        ) : (
            <MoneyText amount={row.base} currency={currency} />
        ),
    amount: (row, currency) => (
        <MoneyText amount={row.amount} currency={currency} />
    ),
};

const totalCells: ReportTotalCells = {
    base: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
    amount: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
};

export default function TaxReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="tax"
            title="Tax"
            description="Tax collected, from the tax lines on the folios by posting date. Reversals reduce the date they were posted on."
            emptyIcon={Landmark}
            emptyTitle="No tax in these dates"
            emptyDescription="Pick another range to see tax."
            cells={cells}
            totalCells={totalCells}
        />
    );
}

TaxReport.layout = {
    breadcrumbs: [{ title: 'Tax', href: tax() }],
};
