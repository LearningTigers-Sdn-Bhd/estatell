import { Deferred } from '@inertiajs/react';
import { MonthTile } from '@/components/pms/dashboard/month-tile';
import { TilesSkeleton } from '@/components/pms/dashboard/tiles-skeleton';
import { TonightTile } from '@/components/pms/dashboard/tonight-tile';
import { UnitCountTile } from '@/components/pms/dashboard/unit-count-tile';
import { arrivals, departures } from '@/routes/reports';

/**
 * The tiles grid: two columns from `lg`, two by two below it. A tile the user may not see is null and leaves its
 * space to the others. The tiles are a deferred prop, so the page opens at once and the cells show skeletons.
 */
export function DashboardTiles({
    tiles,
    can,
}: {
    tiles?: App.Data.Dashboard.DashboardTilesData | null;
    can: App.Data.Dashboard.DashboardAccessData;
}) {
    return (
        <div className="grid grid-cols-2 items-stretch gap-4">
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
                        {tiles.arrivals && (
                            <UnitCountTile
                                title="Arrivals"
                                action="to check in"
                                tile={tiles.arrivals}
                                href={arrivals.url(undefined, {
                                    query: { from: tiles.date, to: tiles.date },
                                })}
                            />
                        )}
                        {tiles.departures && (
                            <UnitCountTile
                                title="Departures"
                                action="to check out"
                                tile={tiles.departures}
                                href={departures.url(undefined, {
                                    query: { from: tiles.date, to: tiles.date },
                                })}
                            />
                        )}
                        {tiles.month && <MonthTile tile={tiles.month} />}
                    </>
                )}
            </Deferred>
        </div>
    );
}
