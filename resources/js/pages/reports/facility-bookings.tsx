import { Tent } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import type { MultiComboboxOption } from '@/components/pms/multi-combobox';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import { facilityBookings } from '@/routes/reports';

type Row = App.Data.Reports.FacilityBookingRow;
type Layout = App.Data.Reports.ReportLayoutData;

type Props = {
    rows: Illuminate.LengthAwarePaginator<number, Row>;
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
    facilities: string[];
    facilityOptions: MultiComboboxOption[];
    statuses: string[];
    statusOptions: MultiComboboxOption[];
};

export default function FacilityBookingsReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="facility-bookings"
            title="Facility bookings"
            description="Who uses each facility, how many and where to find them, by date and time. Each group shows the places booked against the capacity."
            emptyIcon={Tent}
            emptyTitle="No facility bookings"
            emptyDescription="No facility is booked on these dates with the statuses picked."
        />
    );
}

FacilityBookingsReport.layout = {
    breadcrumbs: [{ title: 'Facility bookings', href: facilityBookings() }],
};
