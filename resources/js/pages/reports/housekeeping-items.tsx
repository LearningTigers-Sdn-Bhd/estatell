import { SprayCan } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import { housekeepingItems } from '@/routes/reports';

type Row = App.Data.Reports.HousekeepingItemRow;
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

export default function HousekeepingItemsReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="housekeeping-items"
            title="Housekeeping items"
            description="The items each room needs on the date, such as an extra bed: set up on the first night, remove the day after the last. Mark an item with Show to housekeeping in Charge items."
            emptyIcon={SprayCan}
            emptyTitle="No items to set up"
            emptyDescription="No room needs an extra bed or another housekeeping item on this date."
        />
    );
}

HousekeepingItemsReport.layout = {
    breadcrumbs: [{ title: 'Housekeeping items', href: housekeepingItems() }],
};
