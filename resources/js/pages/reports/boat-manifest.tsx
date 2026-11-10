import { Link, router } from '@inertiajs/react';
import { Ship } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import { BoatsAndCrewPanel } from '@/components/pms/reports/boats-and-crew-panel';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import { buttonVariants } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { boatManifest } from '@/routes/reports';
import { tick as tickRoute } from '@/routes/reports/boat-manifest';

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
    sailings: App.Data.Boats.BoatMainSailingData[];
    boats: App.Data.Boats.BoatData[];
    crew: App.Data.Boats.BoatCrewMemberData[];
    canSetRuns: boolean;
};

export default function BoatManifestReport(props: Props) {
    const setTick = (row: Row, ticked: boolean) =>
        router.put(
            tickRoute.url({ trip: row.key }),
            { ticked },
            { preserveScroll: true, preserveState: true },
        );

    return (
        <ReportScreen
            {...props}
            report="boat-manifest"
            title="Boat manifest"
            description="The guests on each boat of the date, one list per departure. Tick a row when its guests have arrived or left. Charter and own boat trips come next, then the rooms with no boat set, to follow up."
            cells={{
                tick: (row) =>
                    row.not_set ? (
                        <span className="text-muted-foreground">—</span>
                    ) : (
                        <Checkbox
                            aria-label={`${row.lead_guest}: ${row.direction === 'in' ? 'arrived' : 'left'}`}
                            checked={row.ticked}
                            disabled={!props.canSetRuns}
                            onCheckedChange={(checked) =>
                                setTick(row, checked === true)
                            }
                        />
                    ),
            }}
            aside={
                <BoatsAndCrewPanel
                    sailings={props.sailings}
                    boats={props.boats}
                    crew={props.crew}
                    date={props.from}
                    canSet={props.canSetRuns}
                />
            }
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
