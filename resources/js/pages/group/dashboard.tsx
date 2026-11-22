import { Deferred, Head, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import { dashboard as groupDashboard } from '@/routes/group';

type Dashboard = App.Data.Group.GroupDashboardData;
type Block = App.Data.Group.GroupPropertyTilesData;

/** One figure: a label, the value and an optional note under it. */
function Stat({
    label,
    value,
    note,
}: {
    label: string;
    value: React.ReactNode;
    note?: React.ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5">
            <span className="text-xs text-muted-foreground">{label}</span>
            <span className="text-xl font-semibold tabular-nums">{value}</span>
            {note && (
                <span className="text-xs text-muted-foreground">{note}</span>
            )}
        </div>
    );
}

function Totals({ totals }: { totals: App.Data.Group.GroupTotalsData }) {
    return (
        <Card size="sm">
            <CardHeader>
                <CardTitle>All properties</CardTitle>
                <CardDescription>
                    Each property counts on its own business date.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
                {totals.arrivalsTotal !== null && (
                    <Stat
                        label="Arrivals"
                        value={`${totals.arrivalsRemaining} of ${totals.arrivalsTotal}`}
                        note="to check in"
                    />
                )}
                {totals.departuresTotal !== null && (
                    <Stat
                        label="Departures"
                        value={`${totals.departuresRemaining} of ${totals.departuresTotal}`}
                        note="to check out"
                    />
                )}
                {totals.occupancy !== null && (
                    <Stat
                        label="Tonight"
                        value={`${totals.occupancy}%`}
                        note={`${totals.unitsSold} of ${totals.unitsOnSale} sold`}
                    />
                )}
                {totals.newBookings !== null && (
                    <Stat
                        label="New bookings today"
                        value={totals.newBookings}
                        note={`${totals.newRooms} rooms or beds`}
                    />
                )}
                {totals.monthPosted !== null && (
                    <Stat
                        label="Revenue this month"
                        value={
                            <MoneyText
                                amount={totals.monthPosted}
                                currency={totals.currency}
                            />
                        }
                        note={
                            totals.revenueProperties < totals.properties
                                ? `posted, before tax · ${totals.revenueProperties} of ${totals.properties} properties`
                                : 'posted, before tax'
                        }
                    />
                )}
            </CardContent>
        </Card>
    );
}

function PropertyCard({ block }: { block: Block }) {
    const getInitials = useInitials();
    const { property, tiles } = block;

    return (
        <Card size="sm">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Avatar className="overflow-hidden rounded-md after:rounded-md">
                        <AvatarImage
                            src={property.logoUrl ?? undefined}
                            alt=""
                            className="rounded-md object-contain"
                        />
                        <AvatarFallback className="rounded-md bg-primary text-xs font-semibold text-primary-foreground">
                            {getInitials(property.name)}
                        </AvatarFallback>
                    </Avatar>
                    {property.name}
                </CardTitle>
                <CardDescription>
                    ID {property.code}
                    {block.businessDate && (
                        <>
                            {' · Business date '}
                            <DateText date={block.businessDate} />
                        </>
                    )}
                </CardDescription>
                <CardAction>
                    <Link
                        href={dashboard({ property: property.code })}
                        className={buttonVariants({
                            variant: 'outline',
                            size: 'sm',
                        })}
                    >
                        Open
                        <ArrowRight data-icon="inline-end" />
                    </Link>
                </CardAction>
            </CardHeader>
            <CardContent>
                {tiles ? (
                    <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
                        {tiles.arrivals && (
                            <Stat
                                label="Arrivals"
                                value={`${tiles.arrivals.remaining} of ${tiles.arrivals.total}`}
                                note="to check in"
                            />
                        )}
                        {tiles.departures && (
                            <Stat
                                label="Departures"
                                value={`${tiles.departures.remaining} of ${tiles.departures.total}`}
                                note="to check out"
                            />
                        )}
                        {tiles.tonight && (
                            <Stat
                                label="Tonight"
                                value={`${tiles.tonight.occupancy}%`}
                                note={`${tiles.tonight.roomsFree} rooms free${tiles.tonight.bedsFree === null ? '' : ` · ${tiles.tonight.bedsFree} beds free`}`}
                            />
                        )}
                        {tiles.newBookings && (
                            <Stat
                                label="New bookings today"
                                value={tiles.newBookings.bookings}
                                note={`${tiles.newBookings.rooms} rooms or beds`}
                            />
                        )}
                        {tiles.month && (
                            <Stat
                                label="Revenue this month"
                                value={
                                    <MoneyText
                                        amount={tiles.month.posted}
                                        currency={tiles.month.currency}
                                    />
                                }
                                note={`${tiles.month.bookedNights} room nights booked`}
                            />
                        )}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {block.businessDate === null
                            ? 'Not live yet.'
                            : 'Your role at this property does not show these figures.'}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function DashboardSkeleton() {
    return (
        <div className="flex flex-col gap-4">
            {[0, 1, 2].map((index) => (
                <Skeleton key={index} className="h-36 w-full rounded-xl" />
            ))}
        </div>
    );
}

export default function GroupDashboard({
    group,
    dashboard: data,
}: {
    group: { id: number; name: string };
    dashboard?: Dashboard;
}) {
    return (
        <>
            <Head title={`${group.name} dashboard`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={group.name}
                    description="Today at every property you can open, side by side. What you see of each follows your role there."
                />

                <Deferred data="dashboard" fallback={<DashboardSkeleton />}>
                    {data && (
                        <div className="flex flex-col gap-4">
                            <Totals totals={data.totals} />
                            {data.properties.map((block) => (
                                <PropertyCard
                                    key={block.property.code}
                                    block={block}
                                />
                            ))}
                        </div>
                    )}
                </Deferred>
            </div>
        </>
    );
}

GroupDashboard.layout = (props: { group: { id: number; name: string } }) => ({
    breadcrumbs: [
        {
            title: props.group.name,
            href: groupDashboard({ propertyGroup: props.group.id }),
        },
    ],
});
