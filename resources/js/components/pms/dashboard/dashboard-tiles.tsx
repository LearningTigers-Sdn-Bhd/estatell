import { Deferred } from '@inertiajs/react';
import { MonthTile } from '@/components/pms/dashboard/month-tile';
import { TilesSkeleton } from '@/components/pms/dashboard/tiles-skeleton';
import { TonightTile } from '@/components/pms/dashboard/tonight-tile';
import { MovementCard } from '@/components/pms/dashboard/movement-card';
import { HousekeepingTile } from '@/components/pms/dashboard/housekeeping-tile';

/**
 * The summary column of the bento. Hidden cells leave no gap; deferred cells show skeletons.
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
        <div className="flex flex-col gap-4">
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
                        {tiles.tonight && <TonightTile tile={tiles.tonight} />}
                        {(tiles.arrivals || tiles.departures) && (
                            <MovementCard tiles={tiles} />
                        )}
                        {housekeeping !== null && can.canViewHousekeeping && (
                            <Deferred
                                data="housekeeping"
                                fallback={
                                    <TilesSkeleton units={false} reports />
                                }
                            >
                                {housekeeping && (
                                    <HousekeepingTile tile={housekeeping} />
                                )}
                            </Deferred>
                        )}
                        {tiles.month && <MonthTile tile={tiles.month} />}
                    </>
                )}
            </Deferred>
            {tiles === null &&
                housekeeping !== null &&
                can.canViewHousekeeping && (
                    <Deferred
                        data="housekeeping"
                        fallback={<TilesSkeleton units={false} reports />}
                    >
                        {housekeeping && (
                            <HousekeepingTile tile={housekeeping} />
                        )}
                    </Deferred>
                )}
        </div>
    );
}
