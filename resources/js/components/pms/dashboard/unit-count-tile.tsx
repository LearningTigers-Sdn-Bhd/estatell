import { TileCard } from '@/components/pms/dashboard/tile-card';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

/**
 * The Arrivals and Departures tiles: units still to act on, and all units of the business date.
 */
export function UnitCountTile({
    title,
    action,
    tile,
    href,
    icon,
    tone,
}: {
    title: string;
    /** What is left to do, for example "to check in". */
    action: string;
    tile: App.Data.Dashboard.UnitCountTileData;
    href: NonNullable<InertiaLinkProps['href']>;
    icon: LucideIcon;
    tone: 'primary' | 'warning';
}) {
    return (
        <TileCard title={title} href={href} icon={icon} tone={tone}>
            <p className="font-heading text-2xl font-semibold tabular-nums">
                {tile.remaining} of {tile.total}
            </p>
            <p className="text-sm text-muted-foreground">{action}</p>
        </TileCard>
    );
}
