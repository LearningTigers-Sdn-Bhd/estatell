import { Link } from '@inertiajs/react';
import { Ship } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import { buttonVariants } from '@/components/ui/button';
import { boatManifest } from '@/routes/reports';

type Row = App.Data.Reports.BoatManifestRow;
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
    currency: App.Data.Property.CurrencyData | null;
    direction: string;
    directionOptions: EnumOption[];
    emptyTitle: string;
    emptyDescription: string;
    settingsUrl: string | null;
};

export default function BoatManifestReport(props: Props) {
    return (
        <ReportScreen
            {...props}
            report="boat-manifest"
            title="Boat manifest"
            description="The guests on each boat of the date, one list per departure. Charter and own boat trips come last, for the front desk."
            emptyIcon={Ship}
            emptyTitle={props.emptyTitle}
            emptyDescription={props.emptyDescription}
            emptyAction={
                props.settingsUrl ? (
                    <Link
                        href={props.settingsUrl}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        Open Boats settings
                    </Link>
                ) : null
            }
        />
    );
}

BoatManifestReport.layout = {
    breadcrumbs: [{ title: 'Boat manifest', href: boatManifest() }],
};
