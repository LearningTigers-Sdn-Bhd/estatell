import { Deferred, Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarCheck,
    ChartGantt,
    FileText,
    TrendingUp,
    TriangleAlert,
} from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import {
    forecast as groupForecast,
    dashboard as groupDashboard,
    propertyReports,
    reservations,
    timeline,
} from '@/routes/group';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

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
            <CardContent className="flex flex-col gap-4">
                {block.alert && (
                    <p className="flex items-center gap-2 text-sm text-destructive">
                        <TriangleAlert className="size-4 shrink-0" />
                        {block.alert}
                    </p>
                )}
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

/** The properties whose night audit needs someone, from each property's own dashboard alert. */
function Attention({ blocks }: { blocks: Block[] }) {
    const alerts = blocks.filter((block) => block.alert !== null);

    if (alerts.length === 0) {
        return null;
    }

    return (
        <Alert variant="destructive">
            <TriangleAlert />
            <AlertTitle>Needs attention</AlertTitle>
            <AlertDescription>
                <ul className="flex flex-col gap-1">
                    {alerts.map((block) => (
                        <li key={block.property.code}>
                            <Link
                                href={dashboard({
                                    property: block.property.code,
                                })}
                                className="font-medium underline underline-offset-2"
                            >
                                {block.property.name}
                            </Link>
                            : {block.alert}
                        </li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}

/** `2026-12` as "Dec". */
function shortMonth(month: string): string {
    const [year, number] = month.split('-').map(Number);

    return new Date(Date.UTC(year, number - 1, 1)).toLocaleDateString('en-GB', {
        month: 'short',
        timeZone: 'UTC',
    });
}

/** Occupancy on the books for the next six months: one row per property, then all of them. */
function ForecastStrip({
    groupId,
    forecast,
}: {
    groupId: number;
    forecast: App.Data.Group.GroupForecastData;
}) {
    if (forecast.properties.length === 0) {
        return null;
    }

    const rowsFor = (code: string | null) =>
        forecast.months.map(
            (month) =>
                forecast.rows.find(
                    (row) => row.month === month && row.code === code,
                ) ?? null,
        );
    const lines = [
        ...forecast.properties.map((property) => ({
            key: property.code,
            label: property.name,
            rows: rowsFor(property.code),
        })),
        { key: 'all', label: 'All properties', rows: rowsFor(null) },
    ];

    return (
        <Card size="sm">
            <CardHeader>
                <CardTitle>Next 6 months</CardTitle>
                <CardDescription>
                    Occupancy on the books, beside the same month last year.
                </CardDescription>
                <CardAction>
                    <Link
                        href={groupForecast({ propertyGroup: groupId })}
                        className={buttonVariants({
                            variant: 'outline',
                            size: 'sm',
                        })}
                    >
                        Forecast
                        <ArrowRight data-icon="inline-end" />
                    </Link>
                </CardAction>
            </CardHeader>
            <CardContent className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Property</TableHead>
                            {forecast.months.map((month) => (
                                <TableHead key={month} className="text-right">
                                    {shortMonth(month)}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {lines.map((line) => (
                            <TableRow
                                key={line.key}
                                className={cn(
                                    line.key === 'all' && 'font-semibold',
                                )}
                            >
                                <TableCell className="whitespace-nowrap">
                                    {line.label}
                                </TableCell>
                                {line.rows.map((row, index) => (
                                    <TableCell
                                        key={forecast.months[index]}
                                        className="text-right tabular-nums"
                                    >
                                        {row === null ? (
                                            '—'
                                        ) : (
                                            <div className="flex flex-col items-end">
                                                <span>
                                                    {Number(
                                                        row.occupancy,
                                                    ).toFixed(0)}
                                                    %
                                                </span>
                                                {row.lastYearOccupancy !==
                                                    null && (
                                                    <span className="text-xs font-normal text-muted-foreground">
                                                        LY{' '}
                                                        {Number(
                                                            row.lastYearOccupancy,
                                                        ).toFixed(0)}
                                                        %
                                                    </span>
                                                )}
                                            </div>
                                        )}
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

/** The group pages an owner jumps to most. */
function QuickLinks({ groupId }: { groupId: number }) {
    const key = { propertyGroup: groupId };
    const links = [
        { label: 'Reservations', href: reservations(key), icon: CalendarCheck },
        { label: 'Timeline View', href: timeline(key), icon: ChartGantt },
        { label: 'Forecast', href: groupForecast(key), icon: TrendingUp },
        {
            label: 'Property reports',
            href: propertyReports(key),
            icon: FileText,
        },
    ];

    return (
        <div className="flex flex-wrap gap-2">
            {links.map(({ label, href, icon: Icon }) => (
                <Link
                    key={label}
                    href={href}
                    className={buttonVariants({ variant: 'outline' })}
                >
                    <Icon data-icon="inline-start" />
                    {label}
                </Link>
            ))}
        </div>
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
    forecast,
}: {
    group: { id: number; name: string };
    dashboard?: Dashboard;
    forecast?: App.Data.Group.GroupForecastData;
}) {
    return (
        <>
            <Head title={`${group.name} dashboard`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={group.name}
                    description="Today at every property you can open, side by side. What you see of each follows your role there."
                />

                <QuickLinks groupId={group.id} />

                <Deferred data="dashboard" fallback={<DashboardSkeleton />}>
                    {data && (
                        <div className="flex flex-col gap-4">
                            <Attention blocks={data.properties} />
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

                <Deferred
                    data="forecast"
                    fallback={<Skeleton className="h-48 w-full rounded-xl" />}
                >
                    {forecast && (
                        <ForecastStrip groupId={group.id} forecast={forecast} />
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
