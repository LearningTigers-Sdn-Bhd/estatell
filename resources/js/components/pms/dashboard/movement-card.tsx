import { Link } from '@inertiajs/react';
import { LogIn, LogOut } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { arrivals, departures } from '@/routes/reports';

export function MovementCard({
    tiles,
}: {
    tiles: App.Data.Dashboard.DashboardTilesData;
}) {
    return (
        <Card>
            <CardContent className="flex items-stretch gap-4">
                {(
                    [
                        {
                            title: 'Arrivals',
                            action: 'to check in',
                            tile: tiles.arrivals,
                            icon: LogIn,
                            route: arrivals,
                        },
                        {
                            title: 'Departures',
                            action: 'to check out',
                            tile: tiles.departures,
                            icon: LogOut,
                            route: departures,
                        },
                    ] as const
                ).map(
                    ({ title, action, tile, icon: Icon, route }, index) =>
                        tile && (
                            <div key={title} className="contents">
                                {index > 0 && tiles.arrivals && (
                                    <Separator orientation="vertical" />
                                )}
                                <Link
                                    href={route.url(undefined, {
                                        query: {
                                            from: tiles.date,
                                            to: tiles.date,
                                        },
                                    })}
                                    className="flex min-w-0 flex-1 flex-col gap-2 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <h2 className="flex items-center gap-2 font-heading font-medium">
                                        <Icon
                                            aria-hidden
                                            className="size-4 shrink-0 text-muted-foreground"
                                        />
                                        {title}
                                    </h2>
                                    <p className="font-heading text-3xl font-semibold tabular-nums">
                                        {tile.remaining}
                                        <span className="ml-2 text-sm font-normal text-muted-foreground">
                                            of {tile.total}
                                        </span>
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {action}
                                    </p>
                                </Link>
                            </div>
                        ),
                )}
            </CardContent>
        </Card>
    );
}
