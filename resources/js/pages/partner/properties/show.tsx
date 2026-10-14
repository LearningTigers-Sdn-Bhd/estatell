import { Head } from '@inertiajs/react';
import { Info, ToggleRight, Users } from 'lucide-react';
import { DetailLayout } from '@/components/pms/detail-layout';
import { PageHeader } from '@/components/pms/page-header';
import { PropertyFeatures } from '@/components/pms/properties/property-features';
import { PropertyOverview } from '@/components/pms/properties/property-overview';
import { PropertyStaff } from '@/components/pms/properties/property-staff';
import { PropertySummary } from '@/components/pms/properties/property-summary';
import { StatusBadge } from '@/components/pms/status-badge';
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
}: {
    property: Property;
    tab: Tab;
    staff?: StaffMember[];
    features?: PropertyFeature[];
}) {
    return (
        <>
            <Head title={property.name} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={property.name}
                    back={{ href: index(), label: 'Properties' }}
                    badge={<StatusBadge status={property.status} />}
                />

                <DetailLayout
                    activeTab={tab}
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
                    summary={<PropertySummary property={property} />}
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
                            readOnly
                        />
                    )}
                </DetailLayout>
            </div>
        </>
    );
}

PartnerPropertyShow.layout = (props: { property: Property }) => ({
    breadcrumbs: [
        { title: 'Properties', href: index() },
        { title: props.property.name, href: show(props.property.code) },
    ],
});
