import { LogOut } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type { ReportCells } from '@/components/pms/reports/report-screen';
import { checkedOut } from '@/routes/reports';

type Row = App.Data.Reports.CheckedOutRow;
type Layout = App.Data.Reports.ReportLayoutData;

type Props = {
    rows: Illuminate.LengthAwarePaginator<number, Row>;
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
    departure: (row) => <DateText date={row.departure} />,
    checked_out_at: (row) =>
        row.checked_out_at ? (
            <span className="tabular-nums">
                <DateText date={row.checked_out_at.slice(0, 10)} />{' '}
                {row.checked_out_at.slice(11)}
            </span>
        ) : (
            <span className="text-muted-foreground">—</span>
        ),
    balance: (row, currency) => (
        <MoneyText amount={row.balance} currency={currency} />
    ),
};

export default function CheckedOutReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="checked-out"
            title="Checked out"
            description="The units that checked out in the dates you pick, with their balance."
            emptyIcon={LogOut}
            emptyTitle="No check-outs in these dates"
            emptyDescription="Pick other dates to see the units that checked out."
            cells={cells}
        />
    );
}

CheckedOutReport.layout = {
    breadcrumbs: [{ title: 'Checked out', href: checkedOut() }],
};
