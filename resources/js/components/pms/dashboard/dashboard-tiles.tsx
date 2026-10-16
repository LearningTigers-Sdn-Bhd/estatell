import { Deferred } from '@inertiajs/react';
import { MonthTile } from '@/components/pms/dashboard/month-tile';
import { TilesSkeleton } from '@/components/pms/dashboard/tiles-skeleton';
import { MovementCard } from '@/components/pms/dashboard/movement-card';
import { HousekeepingTile } from '@/components/pms/dashboard/housekeeping-tile';

/**
 * The stat strip. Hidden cells leave no gap; each deferred group loads independently.
 */
export function DashboardTiles({
    tiles,
    can,
    housekeeping,
}: {
    tiles?: App.Data.Dashboard.DashboardTilesData | null;
    can: App.Data.Dashboard.DashboardAccessData;
    housekeeping?: App.Data.Dashboard.HousekeepingTileData | null;
}) {
    return (
        <div className="grid grid-cols-2 items-stretch gap-4 xl:auto-cols-fr xl:grid-flow-col xl:grid-cols-none">
            <Deferred
                data="tiles"
                fallback={
                    <TilesSkeleton
                        units={can.canViewReservations}
                        reports={can.canViewReports}
                    />
                }
            >
                {tiles && (
                    <>
                        {(tiles.arrivals || tiles.departures) && (
                            <MovementCard tiles={tiles} />
                        )}
                        {tiles.month && (
                            <div className="order-4 min-w-0">
                                <MonthTile tile={tiles.month} />
                            </div>
                        )}
                    </>
                )}
            </Deferred>
            {housekeeping !== null && can.canViewHousekeeping && (
                <div className="order-3 min-w-0">
                    <Deferred
                        data="housekeeping"
                        fallback={<TilesSkeleton units={false} reports />}
                    >
                        {housekeeping && (
                            <HousekeepingTile tile={housekeeping} />
                        )}
                    </Deferred>
                </div>
            )}
        </div>
    );
}
