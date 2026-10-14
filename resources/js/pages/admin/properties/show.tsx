import { Form, Head, Link } from '@inertiajs/react';
import { Info, ToggleRight, Users } from 'lucide-react';
import { useState } from 'react';
import { DetailLayout } from '@/components/pms/detail-layout';
import { PageHeader } from '@/components/pms/page-header';
import { PropertyFeatures } from '@/components/pms/properties/property-features';
import { PropertyOverview } from '@/components/pms/properties/property-overview';
import { PropertyStaff } from '@/components/pms/properties/property-staff';
import { PropertySummary } from '@/components/pms/properties/property-summary';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes/admin';
import { show as viewAs } from '@/routes/view-as';
import {
    features as featuresTab,
    index,
    reactivate,
    show,
    staff as staffTab,
    suspend,
} from '@/routes/admin/properties';

type Property = App.Data.Platform.AdminPropertyData;
type PropertyFeature = App.Data.Platform.PropertyFeatureData;
type StaffMember = App.Data.Platform.AdminPropertyStaffData;
type Tab = 'overview' | 'staff' | 'features';

export default function PropertyShow({
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
    const [suspendOpen, setSuspendOpen] = useState(false);

    return (
        <>
            <Head title={property.name} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={property.name}
                    back={{ href: index(), label: 'Properties' }}
                    badge={<StatusBadge status={property.status} />}
                    actions={
                        <>
                            <Link
                                href={viewAs(property.code)}
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                View as
                            </Link>
                            {property.status.value === 'suspended' ? (
                                <Form
                                    {...reactivate.form(property.code)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && (
                                                <Spinner data-icon="inline-start" />
                                            )}
                                            Reactivate property
                                        </Button>
                                    )}
                                </Form>
                            ) : (
                                <AlertDialog
                                    open={suspendOpen}
                                    onOpenChange={setSuspendOpen}
                                >
                                    <AlertDialogTrigger
                                        render={
                                            <Button variant="destructive" />
                                        }
                                    >
                                        Suspend property
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>
                                                Suspend {property.name}?
                                            </AlertDialogTitle>
                                            <AlertDialogDescription>
                                                Staff will see a suspension
                                                notice and cannot use property
                                                pages until this property is
                                                reactivated.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <Form
                                            {...suspend.form(property.code)}
                                            options={{ preserveScroll: true }}
                                            onSuccess={() =>
                                                setSuspendOpen(false)
                                            }
                                        >
                                            {({ processing }) => (
                                                <AlertDialogFooter>
                                                    <AlertDialogCancel>
                                                        Cancel
                                                    </AlertDialogCancel>
                                                    <Button
                                                        type="submit"
                                                        variant="destructive"
                                                        disabled={processing}
                                                        focusableWhenDisabled
                                                    >
                                                        {processing && (
                                                            <Spinner data-icon="inline-start" />
                                                        )}
                                                        Suspend property
                                                    </Button>
                                                </AlertDialogFooter>
                                            )}
                                        </Form>
                                    </AlertDialogContent>
                                </AlertDialog>
                            )}
                        </>
                    }
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
                        <PropertyStaff code={property.code} staff={staff} />
                    )}
                    {tab === 'features' && (
                        <PropertyFeatures
                            code={property.code}
                            features={features}
                        />
                    )}
                </DetailLayout>
            </div>
        </>
    );
}

PropertyShow.layout = (props: { property: Property }) => ({
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Properties', href: index() },
        { title: props.property.name, href: show(props.property.code) },
    ],
});
