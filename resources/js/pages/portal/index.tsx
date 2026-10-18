import { Form, Head, Link } from '@inertiajs/react';
import { Building2, Mail } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { Button, buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { show } from '@/routes/portal';
import { accept, decline } from '@/routes/portal/links';

type Property = App.Data.Portal.PortalPropertyData;
type Invitation = App.Data.Portal.PortalInvitationData;

function PropertyRow({ property }: { property: Property }) {
    const open = property.state === 'open';

    return (
        <li
            className={`flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4 ${open ? '' : 'bg-muted/40 text-muted-foreground'}`}
        >
            <div className="flex min-w-0 flex-col gap-1">
                <span className="truncate font-medium">
                    {property.propertyName}
                </span>
                <span className="truncate text-sm text-muted-foreground">
                    {property.companyName}
                </span>
            </div>
            {open ? (
                <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    <span className="flex flex-col">
                        <span className="text-xs text-muted-foreground">
                            Open balance
                        </span>
                        <MoneyText
                            amount={property.openBalance}
                            currency={property.currency}
                        />
                    </span>
                    <span className="flex flex-col">
                        <span className="text-xs text-muted-foreground">
                            Arrivals, next 7 days
                        </span>
                        <span className="tabular-nums">
                            {property.arrivals}
                        </span>
                    </span>
                    <Link
                        href={show({ property: property.propertyCode })}
                        className={buttonVariants({ size: 'sm' })}
                    >
                        Open
                    </Link>
                </div>
            ) : (
                <span className="text-sm">{property.stateLabel}</span>
            )}
        </li>
    );
}

function InvitationRow({ invitation }: { invitation: Invitation }) {
    return (
        <li className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4">
            <div className="flex min-w-0 flex-col gap-1">
                <span className="truncate font-medium">
                    {invitation.propertyName}
                </span>
                <span className="truncate text-sm text-muted-foreground">
                    {invitation.companyName}
                </span>
            </div>
            <div className="flex gap-2">
                <Form
                    {...decline.form({ companyUser: invitation.linkId })}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                            focusableWhenDisabled
                        >
                            Decline
                        </Button>
                    )}
                </Form>
                <Form
                    {...accept.form({ companyUser: invitation.linkId })}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            size="sm"
                            disabled={processing}
                            focusableWhenDisabled
                        >
                            {processing && <Spinner data-icon="inline-start" />}
                            Accept
                        </Button>
                    )}
                </Form>
            </div>
        </li>
    );
}

export default function PortalIndex({
    properties,
    invitations,
}: {
    properties: Property[];
    invitations: Invitation[];
}) {
    return (
        <>
            <Head title="Your properties" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Your properties"
                    description="The properties that gave you a company portal login."
                />

                {properties.length === 0 && invitations.length === 0 && (
                    <EmptyState
                        icon={Building2}
                        title="No properties yet"
                        description="A property links your login to your company. Ask the property to set your email as the company's portal login."
                    />
                )}

                {properties.length > 0 && (
                    <ul className="flex max-w-3xl flex-col gap-3">
                        {properties.map((property) => (
                            <PropertyRow
                                key={property.linkId}
                                property={property}
                            />
                        ))}
                    </ul>
                )}

                {invitations.length > 0 && (
                    <section className="flex max-w-3xl flex-col gap-3">
                        <h2 className="flex items-center gap-2 text-base font-medium">
                            <Mail className="size-4" />
                            Invitations
                        </h2>
                        <ul className="flex flex-col gap-3">
                            {invitations.map((invitation) => (
                                <InvitationRow
                                    key={invitation.linkId}
                                    invitation={invitation}
                                />
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
