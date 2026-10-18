import { Form, Head, Link } from '@inertiajs/react';
import { CalendarDays, Mail } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/portal';
import { show } from '@/routes/portal/bookings';
import { accept, decline } from '@/routes/portal/links';

type Invitation = App.Data.Portal.PortalInvitationData;
type Arrival = App.Data.Portal.PortalArrivalData;

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

function ArrivalRow({ arrival }: { arrival: Arrival }) {
    return (
        <li className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4">
            <div className="flex min-w-0 flex-col gap-1">
                <span className="truncate font-medium">{arrival.title}</span>
                <span className="truncate text-sm text-muted-foreground">
                    {arrival.propertyName} · {arrival.reference}
                    {arrival.externalReference
                        ? ` · ${arrival.externalReference}`
                        : ''}
                </span>
            </div>
            <div className="flex items-center gap-4 text-sm">
                <DateText date={arrival.arrivalDate} />
                <Link
                    href={show({
                        property: arrival.propertyCode,
                        reservation: arrival.reservationId,
                    })}
                    className={buttonVariants({
                        variant: 'outline',
                        size: 'sm',
                    })}
                >
                    View
                </Link>
            </div>
        </li>
    );
}

export default function PortalIndex({
    invitations,
    arrivals,
    arrivalDays,
}: {
    invitations: Invitation[];
    arrivals: Arrival[];
    arrivalDays: number;
}) {
    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Dashboard"
                    description="Invitations and upcoming arrivals across your properties."
                />

                <div className="grid flex-1 gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Mail className="size-4" />
                                Invitations
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {invitations.length === 0 ? (
                                <EmptyState
                                    icon={Mail}
                                    title="No invitations"
                                    description="When a property links your login to a company, the invitation appears here."
                                />
                            ) : (
                                <ul className="flex flex-col gap-3">
                                    {invitations.map((invitation) => (
                                        <InvitationRow
                                            key={invitation.linkId}
                                            invitation={invitation}
                                        />
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CalendarDays className="size-4" />
                                Upcoming arrivals
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {arrivals.length === 0 ? (
                                <EmptyState
                                    icon={CalendarDays}
                                    title="No arrivals coming up"
                                    description={`Confirmed stays arriving in the next ${arrivalDays} days appear here.`}
                                />
                            ) : (
                                <ul className="flex flex-col gap-3">
                                    {arrivals.map((arrival) => (
                                        <ArrivalRow
                                            key={arrival.reservationId}
                                            arrival={arrival}
                                        />
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

PortalIndex.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: index() }],
};
