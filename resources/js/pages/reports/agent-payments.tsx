import { Wallet } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import { agentPayments } from '@/routes/reports';

type Row = App.Data.Reports.AgentPaymentDueRow;
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
    presets: App.Enums.ReportDatePreset[];
    currency: App.Data.Property.CurrencyData | null;
};

export default function AgentPaymentsReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="agent-payments"
            title="Agent payments due"
            description="Agent bookings that still owe their deposit or the rest, overdue first. Follow up with the agent, or cancel the booking to release the rooms."
            emptyIcon={Wallet}
            emptyTitle="Nothing due"
            emptyDescription="Every agent booking with a payment schedule is paid so far."
        />
    );
}

AgentPaymentsReport.layout = {
    breadcrumbs: [{ title: 'Agent payments due', href: agentPayments() }],
};
