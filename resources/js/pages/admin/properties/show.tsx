import { Head } from '@inertiajs/react';
import { Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { DetailLayout } from '@/components/pms/detail-layout';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes/admin';
import { index, show } from '@/routes/admin/properties';

type Property = App.Data.Platform.AdminPropertyData;

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

function Staff({ staff }: { staff: Property['staff'] }) {
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
                        <TableHead>Email verified</TableHead>
                        <TableHead>Created</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {staff.map((user) => (
                        <TableRow key={user.id}>
                            <TableCell className="font-medium">
                                {user.name}
                            </TableCell>
                            <TableCell>{user.email}</TableCell>
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
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

export default function PropertyShow({ property }: { property: Property }) {
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
                    tabs={[
                        {
                            value: 'overview',
                            label: 'Overview',
                            content: <Overview property={property} />,
                        },
                        {
                            value: 'staff',
                            label: `Staff (${property.staff.length})`,
                            content: <Staff staff={property.staff} />,
                        },
                    ]}
                    summary={
                        <dl className="flex flex-col gap-3">
                            <SummaryRow label="Code">
                                <span className="tabular-nums">
                                    {property.code}
                                </span>
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
                />
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
