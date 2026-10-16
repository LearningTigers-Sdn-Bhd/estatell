import { LogIn, LogOut } from 'lucide-react';
import { UnitCountTile } from '@/components/pms/dashboard/unit-count-tile';
import { arrivals, departures } from '@/routes/reports';

export function MovementCard({
    tiles,
}: {
    tiles: App.Data.Dashboard.DashboardTilesData;
}) {
    return (
        <>
            {(
                [
                    {
                        title: 'Arrivals',
                        action: 'to check in',
                        tile: tiles.arrivals,
                        icon: LogIn,
                        tone: 'primary',
                        route: arrivals,
                    },
                    {
                        title: 'Departures',
                        action: 'to check out',
                        tile: tiles.departures,
                        icon: LogOut,
                        tone: 'warning',
                        route: departures,
                    },
                ] as const
            ).map(
                ({ title, action, tile, icon, route, tone }, index) =>
                    tile && (
                        <div
                            key={title}
                            className={
                                index === 0
                                    ? 'order-1 min-w-0'
                                    : 'order-2 min-w-0'
                            }
                        >
                            <UnitCountTile
                                title={title}
                                action={action}
                                tile={tile}
                                icon={icon}
                                tone={tone}
                                href={route.url(undefined, {
                                    query: {
                                        from: tiles.date,
                                        to: tiles.date,
                                    },
                                })}
                            />
                        </div>
                    ),
            )}
        </>
    );
}
