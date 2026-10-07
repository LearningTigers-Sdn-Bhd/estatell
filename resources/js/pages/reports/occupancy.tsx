import { Percent } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type {
    ReportCells,
    ReportTotalCells,
} from '@/components/pms/reports/report-screen';
import { StatusBadge } from '@/components/pms/status-badge';
import { occupancy } from '@/routes/reports';

type Row = App.Data.Reports.OccupancyRow;
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

const percent = (value: string) => (
    <span className="tabular-nums">{Number(value).toFixed(2)}%</span>
);

const cells: ReportCells<Row> = {
    date: (row) => (
        <span className="flex items-center gap-2">
            <DateText date={row.date} />
            {row.live && (
                <StatusBadge
                    status={{ value: 'live', label: 'Live', tone: 'info' }}
                />
            )}
        </span>
    ),
    occupancy: (row) => percent(row.occupancy),
    adr: (row, currency) => <MoneyText amount={row.adr} currency={currency} />,
    revpar: (row, currency) => (
        <MoneyText amount={row.revpar} currency={currency} />
    ),
    revenue: (row, currency) => (
        <MoneyText amount={row.revenue} currency={currency} />
    ),
};

const totalCells: ReportTotalCells = {
    occupancy: (figure) => percent(String(figure)),
    adr: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
    revpar: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
    revenue: (figure, currency) => (
        <MoneyText amount={String(figure)} currency={currency} />
    ),
};

export default function OccupancyReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="occupancy"
            title="Occupancy"
            description="Occupancy, ADR and RevPAR for each business date. Closed dates come from the night audit; the open date is live."
            emptyIcon={Percent}
            emptyTitle="No figures for these dates"
            emptyDescription="Figures start on the first closed business date. Pick another range."
            cells={cells}
            totalCells={totalCells}
        />
    );
}

OccupancyReport.layout = {
    breadcrumbs: [{ title: 'Occupancy', href: occupancy() }],
};
