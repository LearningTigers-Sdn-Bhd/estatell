import { TileCard } from '@/components/pms/dashboard/tile-card';
import type { InertiaLinkProps } from '@inertiajs/react';

/**
 * The Arrivals and Departures tiles: units still to act on, and all units of the business date.
 */
export function UnitCountTile({
    title,
    action,
    tile,
    href,
}: {
    title: string;
    /** What is left to do, for example "to check in". */
    action: string;
    tile: App.Data.Dashboard.UnitCountTileData;
    href: NonNullable<InertiaLinkProps['href']>;
}) {
    return (
        <TileCard title={title} href={href}>
            <p className="font-heading text-3xl font-semibold tabular-nums">
                {tile.remaining}
            </p>
            <p className="text-sm">{action}</p>
            <p className="text-sm text-muted-foreground tabular-nums">
                of {tile.total}
            </p>
        </TileCard>
    );
}
