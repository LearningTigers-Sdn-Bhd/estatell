import { LayoutGrid } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import { CleaningCell } from '@/components/pms/housekeeping/cleaning-cell';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import { houseStatus } from '@/routes/reports';

type Row = App.Data.Reports.HouseStatusRow;
type Layout = App.Data.Reports.ReportLayoutData;

type Props = {
    rows: Illuminate.LengthAwarePaginator<number, Row>;
    totals: App.Data.Reports.ReportTotals;
    sections: App.Data.Reports.ReportSection[];
    sort: string;
    layout: Layout;
    canEditLayout: boolean;
    orientationOptions: EnumOption[];
    from: string;
    to: string;
    maxRangeDays: number | null;
    presets: App.Enums.ReportDatePreset[];
    currency: App.Data.Property.CurrencyData | null;
    housekeepers: App.Data.Housekeeping.HousekeepingStaffData[];
};

export default function HouseStatusReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="house-status"
            title="House status"
            description="Every room housekeeping needs on the date, by boat time: rooms leaving, arriving, moving and staying over, and rooms kept for management. Record the housekeeper's IN and OUT here. The PDF prints it as a grid."
            cells={{
                cleaning: (row) =>
                    row.cleaning ? (
                        <CleaningCell
                            state={row.cleaning}
                            staffOptions={props.housekeepers.map((person) => ({
                                value: String(person.id),
                                label: person.name,
                            }))}
                        />
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            }}
            emptyIcon={LayoutGrid}
            emptyTitle="No rooms on this date"
            emptyDescription="No room arrives, leaves or stays over on this date."
        />
    );
}

HouseStatusReport.layout = {
    breadcrumbs: [{ title: 'House status', href: houseStatus() }],
};
