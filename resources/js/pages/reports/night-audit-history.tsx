import { Moon } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type { ReportCells } from '@/components/pms/reports/report-screen';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { nightAuditHistory, nightAuditPacket } from '@/routes/reports';

type Row = App.Data.Reports.NightAuditHistoryRow;
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
};

const dash = <span className="text-muted-foreground">—</span>;

const cells: ReportCells<Row> = {
    date: (row) => <DateText date={row.date} />,
    status: (row) => <StatusBadge status={row.status} />,
    forced: (row) =>
        row.forced ? (
            <Tooltip>
                <TooltipTrigger render={<span className="cursor-help" />}>
                    Yes
                </TooltipTrigger>
                <TooltipContent>
                    {row.forceReason ?? 'No reason'}
                </TooltipContent>
            </Tooltip>
        ) : null,
    occupancy: (row) => (row.occupancy === null ? dash : row.occupancy),
    revenue: (row, currency) =>
        row.revenue === null ? (
            dash
        ) : (
            <MoneyText amount={row.revenue} currency={currency} />
        ),
    packet: (row) =>
        row.packet ? (
            <a
                href={nightAuditPacket.url({ date: row.date })}
                target="_blank"
                rel="noreferrer"
                className="font-medium underline-offset-4 hover:underline"
            >
                Open packet
            </a>
        ) : null,
};

export default function NightAuditHistoryReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="night-audit-history"
            title="Night audit history"
            description="Every night audit by business date: who ran it, whether it was forced, its exceptions and its figures. A completed audit opens its packet PDF."
            emptyIcon={Moon}
            emptyTitle="No night audits in these dates"
            emptyDescription="Pick another range to see the audits."
            cells={cells}
        />
    );
}

NightAuditHistoryReport.layout = {
    breadcrumbs: [{ title: 'Night audit history', href: nightAuditHistory() }],
};
