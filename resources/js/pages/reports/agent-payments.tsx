import { Wallet } from 'lucide-react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { ReportCellLink } from '@/components/pms/reports/report-cell-link';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import type { ReportCells } from '@/components/pms/reports/report-screen';
import { CancelReservationDialog } from '@/components/pms/reservations/cancel-reservation-dialog';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { agentPayments } from '@/routes/reports';
import { show } from '@/routes/reservations';

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
    presets: App.Enums.ReportDatePreset[] | null;
    canRelease: boolean;
    canWaiveFees: boolean;
    currency: App.Data.Property.CurrencyData | null;
};

export default function AgentPaymentsReport({
    canRelease,
    canWaiveFees,
    ...props
}: Props) {
    const [releasing, setReleasing] = useState<Row | null>(null);

    const cells: ReportCells<Row> = {
        reservation: (row) => (
            <ReportCellLink href={show({ reservation: row.reservation_id })}>
                {row.reservation}
            </ReportCellLink>
        ),
        status: (row) => (
            <div className="flex items-center gap-2">
                <StatusBadge
                    status={{
                        value: row.overdue ? 'overdue' : 'due',
                        label: row.status,
                        tone: row.overdue ? 'danger' : 'warning',
                    }}
                />
                {row.overdue && !row.stay_ended && canRelease && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setReleasing(row)}
                    >
                        Release rooms
                    </Button>
                )}
            </div>
        ),
    };

    return (
        <>
            <ReportScreen
                {...props}
                report="agent-payments"
                title="Agent payments due"
                description="Agent bookings that still owe their deposit or the rest, overdue first. Follow up with the agent, or release the rooms of an overdue booking."
                emptyIcon={Wallet}
                emptyTitle="Nothing due"
                emptyDescription="Every agent booking with a payment schedule is paid so far."
                cells={cells}
            />
            {canRelease && releasing && (
                <CancelReservationDialog
                    open
                    onOpenChange={(open) => !open && setReleasing(null)}
                    reservation={{
                        id: releasing.reservation_id,
                        reference: releasing.reservation,
                        title: releasing.company,
                        companyName: releasing.company,
                    }}
                    defaultReason={`${releasing.stage} not paid by the due time`}
                    canWaiveFees={canWaiveFees}
                    currency={props.currency}
                />
            )}
        </>
    );
}

AgentPaymentsReport.layout = {
    breadcrumbs: [{ title: 'Agent payments due', href: agentPayments() }],
};
