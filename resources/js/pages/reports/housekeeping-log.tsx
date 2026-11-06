import { Clock } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import { housekeepingLog } from '@/routes/reports';

type Row = App.Data.Reports.HousekeepingLogRow;
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
    currency: App.Data.Property.CurrencyData | null;
};

export default function HousekeepingLogReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="housekeeping-log"
            title="Housekeeping log"
            description="Who cleaned which room and when: one row per cleaning, with the housekeeper's IN and OUT time. Recorded on the Housekeeping board and on House status."
            emptyIcon={Clock}
            emptyTitle="No cleanings recorded"
            emptyDescription="Nobody recorded IN or OUT on these dates."
        />
    );
}

HousekeepingLogReport.layout = {
    breadcrumbs: [{ title: 'Housekeeping log', href: housekeepingLog() }],
};
