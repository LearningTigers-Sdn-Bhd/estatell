import { Head } from '@inertiajs/react';
import { Info, ToggleRight, Users } from 'lucide-react';
import { PageHeader } from '@/components/pms/page-header';
import { PropertyFeatures } from '@/components/pms/properties/property-features';
import { PropertyOverview } from '@/components/pms/properties/property-overview';
import { PropertyStaff } from '@/components/pms/properties/property-staff';
import { PropertySummary } from '@/components/pms/properties/property-summary';
import { StatusBadge } from '@/components/pms/status-badge';
import { TabLinks } from '@/components/pms/tab-links';
import { Workspace } from '@/components/pms/workspace';
import {
    features as featuresTab,
    index,
    show,
    staff as staffTab,
} from '@/routes/partner/properties';

type Property = App.Data.Platform.AdminPropertyData;
type PropertyFeature = App.Data.Platform.PropertyFeatureData;
type StaffMember = App.Data.Platform.AdminPropertyStaffData;
type Tab = 'overview' | 'staff' | 'features';

/**
 * The partner's view of a property they brought: the admin tabs, read-only, with no suspend or summary actions.
 */
export default function PartnerPropertyShow({
    property,
    tab,
    staff = [],
    features = [],
    bookingEngineMode,
}: {
    property: Property;
    tab: Tab;
    staff?: StaffMember[];
    features?: PropertyFeature[];
    bookingEngineMode?: App.Data.Platform.BookingEngineModeData;
}) {
    return (
        <>
            <Head title={property.name} />

            <div
                scroll-region=""
                className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 lg:overflow-hidden"
            >
                <PageHeader
                    title={property.name}
                    back={{ href: index(), label: 'Properties' }}
                    badge={<StatusBadge status={property.status} />}
                />

                <TabLinks
                    activeTab={tab}
                    panelWidth="full"
                    className="flex flex-col gap-4 lg:min-h-0 lg:flex-1"
                    panelClassName="flex flex-col lg:min-h-0 lg:flex-1"
                    tabs={[
                        {
                            value: 'overview',
                            label: 'Overview',
                            icon: Info,
                            href: show(property.code),
                        },
                        {
                            value: 'staff',
                            label: `Staff (${property.staffCount})`,
                            icon: Users,
                            href: staffTab(property.code),
                        },
                        {
                            value: 'features',
                            label: 'Features',
                            icon: ToggleRight,
                            href: featuresTab(property.code),
                        },
                    ]}
                >
                    <Workspace
                        asideLabel="Property summary"
                        aside={<PropertySummary property={property} />}
                    >
                        {tab === 'overview' && (
                            <PropertyOverview property={property} />
                        )}
                        {tab === 'staff' && (
                            <PropertyStaff
                                code={property.code}
                                staff={staff}
                                readOnly
                            />
                        )}
                        {tab === 'features' && (
                            <PropertyFeatures
                                code={property.code}
                                features={features}
                                bookingEngineMode={bookingEngineMode}
                                readOnly
                            />
                        )}
                    </Workspace>
                </TabLinks>
            </div>
        </>
    );
}

PartnerPropertyShow.layout = (props: { property: Property }) => ({
    variant: 'fixed' as const,
    breadcrumbs: [
        { title: 'Properties', href: index() },
        { title: props.property.name, href: show(props.property.code) },
    ],
});
