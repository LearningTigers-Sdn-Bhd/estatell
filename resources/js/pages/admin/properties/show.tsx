import { Form, Head, router } from '@inertiajs/react';
import OwnerSetupLinkController from '@/actions/App/Http/Controllers/Admin/OwnerSetupLinkController';
import { Info, ToggleRight, Users } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { DetailLayout } from '@/components/pms/detail-layout';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
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
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes/admin';
import {
    features as featuresTab,
    index,
    reactivate,
    show,
    staff as staffTab,
    suspend,
} from '@/routes/admin/properties';
import { update as updateFeature } from '@/routes/admin/properties/features';

type Property = App.Data.Platform.AdminPropertyData;
type PropertyFeature = App.Data.Platform.PropertyFeatureData;
type StaffMember = App.Data.Platform.AdminPropertyStaffData;
type Tab = 'overview' | 'staff' | 'features';

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-1">
            <h2 className="text-sm font-medium text-muted-foreground">
                {title}
            </h2>
            <div className="text-sm">{children}</div>
        </section>
    );
}

function SummaryRow({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex items-center justify-between gap-4 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right">{children}</dd>
        </div>
    );
}

function Overview({ property }: { property: Property }) {
    const cityLine = [property.postalCode, property.city]
        .filter(Boolean)
        .join(' ');
    const address = [
        property.addressLine1,
        property.addressLine2,
        [cityLine, property.stateCode, property.countryCode]
            .filter(Boolean)
            .join(', '),
    ].filter(Boolean);
    const contact = [property.contactEmail, property.contactPhone].filter(
        Boolean,
    );
    const registration = [
        property.registrationNumber && `Reg. ${property.registrationNumber}`,
        property.tin && `TIN ${property.tin}`,
    ].filter(Boolean);

    return (
        <div className="flex flex-col gap-5">
            <Section title="Address">
                {address.length ? (
                    address.map((line) => <p key={line}>{line}</p>)
                ) : (
                    <p className="text-muted-foreground">Not set yet</p>
                )}
            </Section>
            <Section title="Contact">
                {contact.length ? (
                    <p>{contact.join(' · ')}</p>
                ) : (
                    <p className="text-muted-foreground">Not set yet</p>
                )}
            </Section>
            <Section title="Registration">
                {registration.length ? (
                    <p>{registration.join(' · ')}</p>
                ) : (
                    <p className="text-muted-foreground">Not set yet</p>
                )}
            </Section>
        </div>
    );
}

function Staff({ code, staff }: { code: string; staff: StaffMember[] }) {
    if (staff.length === 0) {
        return <EmptyState icon={Users} title="No staff yet" />;
    }

    return (
        <div className="overflow-hidden rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Email verified</TableHead>
                        <TableHead>Created</TableHead>
                        <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {staff.map((user) => (
                        <TableRow key={user.id}>
                            <TableCell className="font-medium">
                                <span className="flex items-center gap-2">
                                    {user.name}
                                    {user.deactivated && (
                                        <Badge variant="outline">
                                            Deactivated
                                        </Badge>
                                    )}
                                </span>
                            </TableCell>
                            <TableCell>{user.email}</TableCell>
                            <TableCell>
                                {user.role ?? (
                                    <span className="text-muted-foreground">
                                        No role
                                    </span>
                                )}
                            </TableCell>
                            <TableCell>
                                <Badge
                                    variant={
                                        user.verified ? 'secondary' : 'outline'
                                    }
                                >
                                    {user.verified
                                        ? 'Verified'
                                        : 'Not verified'}
                                </Badge>
                            </TableCell>
                            <TableCell className="tabular-nums">
                                <DateText date={user.createdAt} />
                            </TableCell>
                            <TableCell className="text-right">
                                {user.canSendSetupLink && (
                                    <Form
                                        {...OwnerSetupLinkController.form(code)}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="outline"
                                                disabled={processing}
                                                focusableWhenDisabled
                                            >
                                                {processing && (
                                                    <Spinner data-icon="inline-start" />
                                                )}
                                                Send setup email
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function Features({
    code,
    features,
}: {
    code: string;
    features: PropertyFeature[];
}) {
    const toggle = (feature: PropertyFeature['feature'], active: boolean) => {
        router
            .optimistic<{ features: PropertyFeature[] }>((props) => ({
                features: props.features.map((item) =>
                    item.feature === feature ? { ...item, active } : item,
                ),
            }))
            .put(
                updateFeature.url({ property: code, feature }),
                { active },
                { preserveScroll: true },
            );
    };

    return (
        <FieldGroup className="max-w-3/4">
            {features.map((item) => (
                <Field key={item.feature} orientation="horizontal">
                    <FieldContent>
                        <FieldLabel htmlFor={`feature-${item.feature}`}>
                            {item.label}
                        </FieldLabel>
                        <FieldDescription>{item.description}</FieldDescription>
                    </FieldContent>
                    <Switch
                        id={`feature-${item.feature}`}
                        checked={item.active}
                        onCheckedChange={(active) =>
                            toggle(item.feature, active)
                        }
                    />
                </Field>
            ))}
        </FieldGroup>
    );
}

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
                        property.status.value === 'suspended' ? (
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
                                    render={<Button variant="destructive" />}
                                >
                                    Suspend property
                                </AlertDialogTrigger>
                                <AlertDialogContent>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>
                                            Suspend {property.name}?
                                        </AlertDialogTitle>
                                        <AlertDialogDescription>
                                            Staff will see a suspension notice
                                            and cannot use property pages until
                                            this property is reactivated.
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <Form
                                        {...suspend.form(property.code)}
                                        options={{ preserveScroll: true }}
                                        onSuccess={() => setSuspendOpen(false)}
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
                        )
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
                    summary={
                        <dl className="flex flex-col gap-3">
                            <SummaryRow label="Code">
                                <span className="tabular-nums">
                                    {property.code}
                                </span>
                            </SummaryRow>
                            <SummaryRow label="Owner">
                                {property.ownerName ? (
                                    <span className="flex flex-col">
                                        <span>{property.ownerName}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {property.ownerEmail}
                                        </span>
                                    </span>
                                ) : (
                                    <span className="text-muted-foreground">
                                        No owner
                                    </span>
                                )}
                            </SummaryRow>
                            <SummaryRow label="Status">
                                <StatusBadge status={property.status} />
                            </SummaryRow>
                            <SummaryRow label="Type">
                                {property.type ?? '—'}
                            </SummaryRow>
                            <SummaryRow label="Created">
                                <DateText date={property.createdAt} />
                            </SummaryRow>
                            <SummaryRow label="Live since">
                                <DateText date={property.wentLiveAt} />
                            </SummaryRow>
                            {property.suspendedAt && (
                                <SummaryRow label="Suspended">
                                    <DateText date={property.suspendedAt} />
                                </SummaryRow>
                            )}
                        </dl>
                    }
                >
                    {tab === 'overview' && <Overview property={property} />}
                    {tab === 'staff' && (
                        <Staff code={property.code} staff={staff} />
                    )}
                    {tab === 'features' && (
                        <Features code={property.code} features={features} />
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
