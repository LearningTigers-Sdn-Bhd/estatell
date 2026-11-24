import { Deferred, Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    BedDouble,
    Coins,
    LogIn,
    LogOut,
    TrendingUp,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import { GroupKpiCard } from '@/components/pms/group/group-kpi-card';
import type { KpiChange } from '@/components/pms/group/group-kpi-card';
import { OccupancyOutlookChart } from '@/components/pms/group/occupancy-outlook-chart';
import { RevenueSplitChart } from '@/components/pms/group/revenue-split-chart';
import { formatDate } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    dashboard as groupDashboard,
    forecast as groupForecast,
} from '@/routes/group';

type Dashboard = App.Data.Group.GroupDashboardData;
type Forecast = App.Data.Group.GroupForecastData;
type ForecastRow = App.Data.Group.GroupForecastRowData;
type Block = App.Data.Group.GroupPropertyTilesData;

/** The change of a figure against last year: points for a rate, a percentage for money. */
function changeAgainst(
    now: number,
    before: number | null,
    kind: 'points' | 'percent',
): KpiChange | null {
    if (before === null || (kind === 'percent' && before === 0)) {
        return null;
    }

    const delta =
        kind === 'points' ? now - before : ((now - before) / before) * 100;
    const direction =
        Math.abs(delta) < 0.05 ? 'same' : delta > 0 ? 'up' : 'down';
    const sign = delta > 0 ? '+' : '';

    return {
        text: `${sign}${delta.toFixed(1)}${kind === 'points' ? ' pts' : '%'} vs last year`,
        direction,
    };
}

/** Money for a headline card: two decimals under 1,000, whole above, "12.4K" style from 100,000. */
function compactMoney(amount: number, currency: string | undefined): string {
    const decimals = amount < 1000 ? 2 : 0;
    const text = new Intl.NumberFormat(undefined, {
        notation: amount >= 100_000 ? 'compact' : 'standard',
        minimumFractionDigits: amount >= 100_000 ? 0 : decimals,
        maximumFractionDigits: amount >= 100_000 ? 1 : decimals,
    }).format(amount);

    return `${currency ?? ''} ${text}`.trim();
}

/**
 * This month across the group, actual and on the books, against the same month last year: occupancy, ADR, RevPAR and
 * revenue. The figures come from the forecast's first month; the browser only works out the change.
 */
function MonthKpis({
    row,
    currency,
}: {
    row: ForecastRow;
    currency: string | undefined;
}) {
    const occupancy = Number(row.occupancy);
    const lastYearOccupancy =
        row.lastYearOccupancy === null ? null : Number(row.lastYearOccupancy);
    const room = Number(row.roomRevenue ?? 0);
    const lastYearRoom =
        row.lastYearRoomRevenue === null
            ? null
            : Number(row.lastYearRoomRevenue);
    const adr = Number(row.adr ?? 0);
    const lastYearAdr =
        lastYearRoom !== null && row.lastYearSold
            ? lastYearRoom / row.lastYearSold
            : null;
    const revpar = Number(row.revpar ?? 0);
    const lastYearRevpar =
        lastYearRoom !== null && row.lastYearAvailable
            ? lastYearRoom / row.lastYearAvailable
            : null;
    const money = row.roomRevenue !== null;

    return (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <GroupKpiCard
                label="Occupancy this month"
                icon={BedDouble}
                value={`${occupancy.toFixed(1)}%`}
                note={`${row.sold.toLocaleString()} of ${row.available.toLocaleString()} nights`}
                change={changeAgainst(occupancy, lastYearOccupancy, 'points')}
            />
            <GroupKpiCard
                label="ADR"
                icon={Coins}
                value={money ? compactMoney(adr, currency) : '—'}
                note="Room revenue per paid night"
                change={
                    money ? changeAgainst(adr, lastYearAdr, 'percent') : null
                }
            />
            <GroupKpiCard
                label="RevPAR"
                icon={TrendingUp}
                value={money ? compactMoney(revpar, currency) : '—'}
                note="Room revenue per available night"
                change={
                    money
                        ? changeAgainst(revpar, lastYearRevpar, 'percent')
                        : null
                }
            />
            <GroupKpiCard
                label="Revenue this month"
                icon={Wallet}
                value={
                    money
                        ? compactMoney(Number(row.totalRevenue ?? 0), currency)
                        : '—'
                }
                note="Rooms and extras, posted and on the books"
                change={
                    money ? changeAgainst(room, lastYearRoom, 'percent') : null
                }
            />
        </div>
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

/** Tonight and the next six nights as small bars, tallest at 100%. */
function NightBars({
    nights,
}: {
    nights: App.Data.Dashboard.TonightNightData[];
}) {
    return (
        <div
            className="flex h-8 items-end gap-0.5"
            role="img"
            aria-label={`Occupancy for the next ${nights.length} nights: ${nights.map((night) => `${night.occupancy}%`).join(', ')}`}
        >
            {nights.map((night, index) => (
                <span
                    key={night.date}
                    title={`${formatDate(night.date)}: ${night.occupancy}%`}
                    className={cn(
                        'w-2 rounded-sm',
                        index === 0 ? 'bg-primary' : 'bg-primary/40',
                    )}
                    style={{ height: `${Math.max(night.occupancy, 4)}%` }}
                />
            ))}
        </div>
    );
}

/** A to-do count: what is left of the day's total, muted when nothing is left. */
function Remaining({
    tile,
}: {
    tile: App.Data.Dashboard.UnitCountTileData | null;
}) {
    if (tile === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span
            className={cn(
                'tabular-nums',
                tile.remaining === 0 && 'text-muted-foreground',
            )}
        >
            {tile.remaining}
            <span className="text-muted-foreground"> / {tile.total}</span>
        </span>
    );
}

/**
 * Today at every property, one row each, so the lodges compare side by side: arrivals and departures left, tonight's
 * occupancy with the week ahead, new bookings and revenue posted this month.
 */
function PropertyComparison({ blocks }: { blocks: Block[] }) {
    const getInitials = useInitials();

    return (
        <Card size="sm" className="gap-2">
            <CardHeader>
                <CardTitle>Today by property</CardTitle>
                <CardDescription>
                    Each property on its own business date. What you see follows
                    your role there.
                </CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto px-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="pl-4">Property</TableHead>
                            <TableHead className="text-right">
                                <span className="inline-flex items-center gap-1">
                                    <LogIn className="size-3.5" />
                                    Arrivals
                                </span>
                            </TableHead>
                            <TableHead className="text-right">
                                <span className="inline-flex items-center gap-1">
                                    <LogOut className="size-3.5" />
                                    Departures
                                </span>
                            </TableHead>
                            <TableHead>Tonight</TableHead>
                            <TableHead>Next 7 nights</TableHead>
                            <TableHead className="text-right">
                                New today
                            </TableHead>
                            <TableHead className="text-right">
                                Revenue this month
                            </TableHead>
                            <TableHead className="w-12 pr-4">
                                <span className="sr-only">Open</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {blocks.map((block) => {
                            const { property, tiles } = block;

                            return (
                                <TableRow key={property.code}>
                                    <TableCell className="pl-4">
                                        <div className="flex items-center gap-2">
                                            <Avatar className="size-8 overflow-hidden rounded-md after:rounded-md">
                                                <AvatarImage
                                                    src={
                                                        property.logoUrl ??
                                                        undefined
                                                    }
                                                    alt=""
                                                    className="rounded-md object-contain"
                                                />
                                                <AvatarFallback className="rounded-md bg-primary text-xs font-semibold text-primary-foreground">
                                                    {getInitials(property.name)}
                                                </AvatarFallback>
                                            </Avatar>
                                            <div className="flex min-w-0 flex-col">
                                                <span className="flex items-center gap-1 font-medium">
                                                    {property.name}
                                                    {block.alert && (
                                                        <TriangleAlert
                                                            className="size-3.5 text-destructive"
                                                            aria-label={
                                                                block.alert
                                                            }
                                                        />
                                                    )}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {block.businessDate
                                                        ? `Business date ${formatDate(block.businessDate)}`
                                                        : 'Not live yet'}
                                                </span>
                                            </div>
                                        </div>
                                    </TableCell>
                                    {tiles === null ? (
                                        <TableCell
                                            colSpan={6}
                                            className="text-sm text-muted-foreground"
                                        >
                                            {block.businessDate === null
                                                ? 'Not live yet.'
                                                : 'Your role at this property does not show these figures.'}
                                        </TableCell>
                                    ) : (
                                        <>
                                            <TableCell className="text-right">
                                                <Remaining
                                                    tile={tiles.arrivals}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Remaining
                                                    tile={tiles.departures}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                {tiles.tonight ? (
                                                    <div className="flex min-w-32 flex-col gap-1">
                                                        <span className="text-sm font-medium tabular-nums">
                                                            {
                                                                tiles.tonight
                                                                    .occupancy
                                                            }
                                                            %
                                                            <span className="font-normal text-muted-foreground">
                                                                {' '}
                                                                ·{' '}
                                                                {tiles.tonight
                                                                    .nights[0]
                                                                    ?.sold ??
                                                                    0}{' '}
                                                                of{' '}
                                                                {tiles.tonight
                                                                    .nights[0]
                                                                    ?.total ??
                                                                    0}
                                                            </span>
                                                        </span>
                                                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                                            <div
                                                                className="h-full rounded-full bg-primary"
                                                                style={{
                                                                    width: `${Math.min(tiles.tonight.occupancy, 100)}%`,
                                                                }}
                                                            />
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {tiles.tonight ? (
                                                    <NightBars
                                                        nights={
                                                            tiles.tonight.nights
                                                        }
                                                    />
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {tiles.newBookings
                                                    ? tiles.newBookings.bookings
                                                    : '—'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {tiles.month ? (
                                                    <div className="flex flex-col items-end">
                                                        <MoneyText
                                                            amount={
                                                                tiles.month
                                                                    .posted
                                                            }
                                                            currency={
                                                                tiles.month
                                                                    .currency
                                                            }
                                                        />
                                                        <span className="text-xs text-muted-foreground">
                                                            {
                                                                tiles.month
                                                                    .bookedNights
                                                            }{' '}
                                                            nights booked
                                                        </span>
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </TableCell>
                                        </>
                                    )}
                                    <TableCell className="pr-4">
                                        <Link
                                            href={dashboard({
                                                property: property.code,
                                            })}
                                            aria-label={`Open ${property.name}`}
                                            className={buttonVariants({
                                                variant: 'ghost',
                                                size: 'icon',
                                            })}
                                        >
                                            <ArrowRight />
                                        </Link>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

/** The month figures, the outlook and the revenue split, all from the forecast. */
function Outlook({
    groupId,
    forecast,
}: {
    groupId: number;
    forecast: Forecast;
}) {
    if (forecast.properties.length === 0) {
        return null;
    }

    const thisMonth = forecast.months[0];
    const all = forecast.rows.find(
        (row) => row.month === thisMonth && row.code === null,
    );
    const split = forecast.properties.map((property) => {
        const row = forecast.rows.find(
            (item) => item.month === thisMonth && item.code === property.code,
        );

        return {
            name: property.name,
            room: Number(row?.roomRevenue ?? 0),
            extra: Number(row?.extraRevenue ?? 0),
        };
    });

    return (
        <>
            {all && <MonthKpis row={all} currency={forecast.currency?.code} />}
            <div className="grid gap-3 xl:grid-cols-5">
                <Card size="sm" className="xl:col-span-3">
                    <CardHeader>
                        <CardTitle>Occupancy outlook</CardTitle>
                        <CardDescription>
                            On the books for the next six months, by property,
                            against the group last year.
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
                    <CardContent>
                        <OccupancyOutlookChart forecast={forecast} />
                    </CardContent>
                </Card>
                <Card size="sm" className="xl:col-span-2">
                    <CardHeader>
                        <CardTitle>Revenue this month</CardTitle>
                        <CardDescription>
                            Rooms and extras by property, posted and on the
                            books.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {forecast.currency === null ? (
                            <p className="text-sm text-muted-foreground">
                                The properties use different currencies. See
                                each property’s dashboard.
                            </p>
                        ) : (
                            <RevenueSplitChart
                                rows={split}
                                currency={forecast.currency}
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

export default function GroupDashboard({
    group,
    dashboard: data,
    forecast,
}: {
    group: { id: number; name: string };
    dashboard?: Dashboard;
    forecast?: Forecast;
}) {
    return (
        <>
            <Head title={`${group.name} dashboard`} />

            <div className="flex flex-1 flex-col gap-3 p-4">
                <Deferred
                    data="dashboard"
                    fallback={<Skeleton className="h-16 w-full rounded-xl" />}
                >
                    {data && <Attention blocks={data.properties} />}
                </Deferred>

                <Deferred
                    data="forecast"
                    fallback={
                        <div className="flex flex-col gap-3">
                            <Skeleton className="h-28 w-full rounded-xl" />
                            <Skeleton className="h-72 w-full rounded-xl" />
                        </div>
                    }
                >
                    {forecast && (
                        <Outlook groupId={group.id} forecast={forecast} />
                    )}
                </Deferred>

                <Deferred
                    data="dashboard"
                    fallback={<Skeleton className="h-48 w-full rounded-xl" />}
                >
                    {data && <PropertyComparison blocks={data.properties} />}
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
