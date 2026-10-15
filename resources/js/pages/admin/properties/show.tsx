import { Form, Head, Link } from '@inertiajs/react';
import { Hourglass, Info, Store, ToggleRight, Users } from 'lucide-react';
import { useState } from 'react';
import { DetailLayout } from '@/components/pms/detail-layout';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { MovePropertyPartner } from '@/components/pms/properties/move-property-partner';
import { PropertyFeatures } from '@/components/pms/properties/property-features';
import { PropertyOtaLists } from '@/components/pms/properties/property-ota-lists';
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
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes/admin';
import { show as goLive } from '@/routes/admin/properties/go-live';
import { show as viewAs } from '@/routes/view-as';
import {
    features as featuresTab,
    index,
    otaLists as otaListsTab,
    reactivate,
    show,
    staff as staffTab,
    suspend,
} from '@/routes/admin/properties';

type Property = App.Data.Platform.AdminPropertyData;
type PropertyFeature = App.Data.Platform.PropertyFeatureData;
type StaffMember = App.Data.Platform.AdminPropertyStaffData;
type OtaListRow = App.Data.Platform.AdminOtaListRowData;
type Tab = 'overview' | 'staff' | 'features' | 'ota-lists';

export default function PropertyShow({
    property,
    tab,
    staff = [],
    features = [],
    otaLists = [],
    partnerOptions,
}: {
    property: Property;
    tab: Tab;
    staff?: StaffMember[];
    features?: PropertyFeature[];
    otaLists?: OtaListRow[];
    partnerOptions: EnumOption[];
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
                            <MovePropertyPartner
                                property={property}
                                partnerOptions={partnerOptions}
                            />
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

                {property.status.value === 'pending_approval' && (
                    <Alert>
                        <Hourglass />
                        <AlertTitle>Waiting for approval</AlertTitle>
                        <AlertDescription className="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <span>
                                The owner sent this property for go-live review.
                            </span>
                            <Link
                                href={goLive(property.code)}
                                className={buttonVariants({
                                    variant: 'outline',
                                    size: 'sm',
                                })}
                            >
                                Review
                            </Link>
                        </AlertDescription>
                    </Alert>
                )}

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
                        {
                            value: 'ota-lists',
                            label: 'OTA lists',
                            icon: Store,
                            href: otaListsTab(property.code),
                        },
                    ]}
                    summary={
                        <PropertySummary property={property} showPartner />
                    }
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
                    {tab === 'ota-lists' && (
                        <PropertyOtaLists
                            code={property.code}
                            rows={otaLists}
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
