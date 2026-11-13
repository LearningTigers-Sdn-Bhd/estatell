import { Head } from '@inertiajs/react';
import { Clock, SprayCan } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { ReportScreen } from '@/components/pms/reports/report-screen';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { housekeeping } from '@/routes/reports';

type Row =
    | App.Data.Reports.HousekeepingItemRow
    | App.Data.Reports.HousekeepingLogRow;
type Layout = App.Data.Reports.ReportLayoutData;

type Tab = {
    value: string;
    label: string;
    href: string;
};

type Props = {
    tab: string;
    tabs: Tab[];
    rows: Illuminate.LengthAwarePaginator<number, Row>;
    totals: App.Data.Reports.ReportTotals | null;
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

const screens: Record<
    string,
    {
        report: App.Enums.ReportKey;
        icon: LucideIcon;
        description: string;
        emptyTitle: string;
        emptyDescription: string;
    }
> = {
    items: {
        report: 'housekeeping-items',
        icon: SprayCan,
        description:
            'The items each room needs on the date, such as an extra bed: set up on the first night, remove the day after the last. Mark an item with Show to housekeeping in Charge items.',
        emptyTitle: 'No items to set up',
        emptyDescription:
            'No room needs an extra bed or another housekeeping item on this date.',
    },
    log: {
        report: 'housekeeping-log',
        icon: Clock,
        description:
            "Who cleaned which room and when: one row per cleaning, with the housekeeper's IN and OUT time. Recorded on the Housekeeping board and on House status.",
        emptyTitle: 'No cleanings recorded',
        emptyDescription: 'Nobody recorded IN or OUT on these dates.',
    },
};

/** The report tabs, for the tab bar and the breadcrumbs. */
function housekeepingTabs(tabs: Tab[]): TabLink[] {
    return tabs.map((item) => ({
        ...item,
        icon: screens[item.value].icon,
    }));
}

export default function HousekeepingReport({ tab, tabs, ...props }: Props) {
    const screen = screens[tab] ?? screens.items;
    const label = tabs.find((item) => item.value === tab)?.label ?? 'Items';

    return (
        <>
            <Head title="Housekeeping" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Housekeeping"
                    description="The items each room needs set up, and who cleaned which room and when."
                />

                <TabLinks
                    activeTab={tab}
                    panelWidth="full"
                    className="flex-1 gap-4"
                    panelClassName="flex flex-1 flex-col"
                    tabs={housekeepingTabs(tabs)}
                >
                    <ReportScreen
                        key={tab}
                        {...props}
                        embedded
                        query={{ tab }}
                        layoutLabel={`Housekeeping › ${label}`}
                        report={screen.report}
                        title={label}
                        description={screen.description}
                        emptyIcon={screen.icon}
                        emptyTitle={screen.emptyTitle}
                        emptyDescription={screen.emptyDescription}
                    />
                </TabLinks>
            </div>
        </>
    );
}

HousekeepingReport.layout = (props: Pick<Props, 'tab' | 'tabs'>) => ({
    breadcrumbs: withTabCrumb(
        [{ title: 'Housekeeping', href: housekeeping() }],
        housekeepingTabs(props.tabs),
        props.tab,
    ),
});
