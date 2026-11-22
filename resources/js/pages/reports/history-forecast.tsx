import { ChartGantt } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type {
    ReportCells,
    ReportTotalCells,
} from '@/components/pms/reports/report-screen';
import { StatusBadge } from '@/components/pms/status-badge';
import { historyForecast } from '@/routes/reports';

type Row = App.Data.Reports.HistoryForecastRow;
type Layout = App.Data.Reports.ReportLayoutData;
type MoneyKey = 'adr' | 'revpar' | 'room_charges' | 'extra_charges' | 'tax';

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
    presets: App.Enums.ReportDatePreset[] | null;
    currency: App.Data.Property.CurrencyData | null;
};

const percent = (value: string) => (
    <span className="tabular-nums">{Number(value).toFixed(2)}%</span>
);

const money =
    (key: MoneyKey) =>
    (row: Row, currency: App.Data.Property.CurrencyData | null) => (
        <MoneyText amount={row[key]} currency={currency} plain />
    );

const moneyTotal = (
    figure: string | number,
    currency: App.Data.Property.CurrencyData | null,
) => <MoneyText amount={String(figure)} currency={currency} plain />;

const cells: ReportCells<Row> = {
    date: (row) => <DateText date={row.date} />,
    basis: (row) => <StatusBadge status={row.basis} />,
    occupancy: (row) => percent(row.occupancy),
    adr: money('adr'),
    revpar: money('revpar'),
    room_charges: money('room_charges'),
    extra_charges: money('extra_charges'),
    tax: money('tax'),
    receipts: (row, currency) =>
        row.receipts === null ? (
            <span className="text-muted-foreground">—</span>
        ) : (
            <MoneyText amount={row.receipts} currency={currency} plain />
        ),
};

const totalCells: ReportTotalCells = {
    occupancy: (figure) => percent(String(figure)),
    adr: moneyTotal,
    revpar: moneyTotal,
    room_charges: moneyTotal,
    extra_charges: moneyTotal,
    tax: moneyTotal,
    receipts: moneyTotal,
};

export default function HistoryForecastReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="history-forecast"
            title="History & forecast"
            description="Rooms, pax and charges for each date. Closed dates come from the night audit and the ledger; today and later dates from the bookings on hand, at today's prices and taxes."
            emptyIcon={ChartGantt}
            emptyTitle="No figures for these dates"
            emptyDescription="Closed dates start on the first night audit. Pick another range."
            cells={cells}
            totalCells={totalCells}
        />
    );
}

HistoryForecastReport.layout = {
    breadcrumbs: [{ title: 'History & forecast', href: historyForecast() }],
};
