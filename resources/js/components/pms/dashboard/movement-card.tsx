import { LogIn, LogOut } from 'lucide-react';
import { UnitCountTile } from '@/components/pms/dashboard/unit-count-tile';
import { index } from '@/routes/reservations';

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
                        status: 'arrivals',
                    },
                    {
                        title: 'Departures',
                        action: 'to check out',
                        tile: tiles.departures,
                        icon: LogOut,
                        tone: 'warning',
                        status: 'departures',
                    },
                ] as const
            ).map(
                ({ title, action, tile, icon, status, tone }, position) =>
                    tile && (
                        <div
                            key={title}
                            className={
                                position === 0
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
                                href={index.url(undefined, {
                                    query: { filter: { status } },
                                })}
                            />
                        </div>
                    ),
            )}
        </>
    );
}
