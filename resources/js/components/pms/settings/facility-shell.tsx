import { router } from '@inertiajs/react';
import { Clock, FileText } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { facilityValues } from '@/components/pms/settings/facility-sheet';
import { FacilitySummary } from '@/components/pms/settings/facility-summary';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Workspace } from '@/components/pms/workspace';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { toast } from '@/components/ui/toast';
import { index, show, update } from '@/routes/settings/facilities';
import { index as sessionsIndex } from '@/routes/settings/facilities/sessions';

type Facility = App.Data.Facilities.FacilityData;

export type FacilityTab = 'details' | 'sessions';

/**
 * The tabs of the facility page, for the tab bar and the breadcrumbs.
 */
export function facilityTabs(facility: Facility): TabLink[] {
    const key = { facility: facility.id };
    const tabs: TabLink[] = [
        {
            value: 'details',
            label: 'Details',
            icon: FileText,
            href: show.url(key),
        },
    ];

    tabs.push({
        value: 'sessions',
        label:
            facility.sessionCount > 0
                ? `Sessions (${facility.sessionCount})`
                : 'Sessions',
        title: 'Sessions',
        icon: Clock,
        href: sessionsIndex.url(key),
    });

    return tabs;
}

/** Facilities › {facility} › {tab}. */
export function facilityBreadcrumbs(
    props: { facility: Facility },
    activeTab: FacilityTab,
) {
    return withTabCrumb(
        [
            { title: 'Facilities', href: index() },
            {
                title: props.facility.name,
                href: show({ facility: props.facility.id }),
            },
        ],
        facilityTabs(props.facility),
        activeTab,
    );
}

/**
 * Header, tab links and workspace of the facility page. Each tab is its own page and URL. The header's Available switch
 * saves at once, like the one on the list. The summary sits in the aside on every tab.
 */
export function FacilityShell({
    facility,
    activeTab,
    currency,
    children,
}: {
    facility: Facility;
    activeTab: FacilityTab;
    currency: App.Data.Property.CurrencyData | null;
    children: ReactNode;
}) {
    const [saving, setSaving] = useState(false);

    const toggleActive = (active: boolean) => {
        router
            .optimistic<{ facility: Facility }>((props) => ({
                facility: { ...props.facility, active },
            }))
            .put(
                update.url({ facility: facility.id }),
                { ...facilityValues(facility), active },
                {
                    preserveScroll: true,
                    onStart: () => setSaving(true),
                    onFinish: () => setSaving(false),
                    onError: (errors) =>
                        toast.add({
                            title:
                                Object.values(errors)[0] ??
                                'The change could not be saved.',
                            type: 'error',
                        }),
                },
            );
    };

    return (
        <div className="flex flex-1 flex-col gap-6 p-4">
            <PageHeader
                title={facility.name}
                back={{ href: index.url(), label: 'Facilities' }}
                badge={
                    facility.active ? undefined : (
                        <Badge variant="secondary">Unavailable</Badge>
                    )
                }
                actions={
                    <div className="flex items-center gap-2">
                        <Switch
                            id="facility-available"
                            checked={facility.active}
                            disabled={saving}
                            onCheckedChange={toggleActive}
                        />
                        <Label htmlFor="facility-available">Available</Label>
                    </div>
                }
            />
            <TabLinks
                tabs={facilityTabs(facility)}
                activeTab={activeTab}
                panelWidth="full"
                className="flex flex-col gap-4 lg:flex-1"
                panelClassName="flex flex-col lg:flex-1"
            >
                <Workspace
                    scroll="page"
                    asideLabel="Facility summary"
                    aside={
                        <FacilitySummary
                            facility={facility}
                            currency={currency}
                        />
                    }
                >
                    {children}
                </Workspace>
            </TabLinks>
        </div>
    );
}
