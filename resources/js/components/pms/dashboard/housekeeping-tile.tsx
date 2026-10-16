import { Brush } from 'lucide-react';
import { TileCard } from '@/components/pms/dashboard/tile-card';
import { index } from '@/routes/housekeeping';
import { cn } from 'cn';
import { DashboardBadge } from '@/components/pms/dashboard/dashboard-badge';

export function HousekeepingTile({
    tile,
}: {
    tile: App.Data.Dashboard.HousekeepingTileData;
}) {
    return (
        <TileCard
            title="Housekeeping"
            icon={Brush}
            href={index.url()}
            tone={tile.dirty > 0 ? 'warning' : 'neutral'}
        >
            <p className="font-heading text-2xl font-semibold tabular-nums">
                {tile.dirty}{' '}
                <span className={cn(tile.dirty > 0 && 'text-warning-text')}>
                    dirty
                </span>
            </p>
            <div className="flex flex-wrap items-center gap-1 text-sm text-muted-foreground tabular-nums">
                <span>{tile.toInspect} to inspect ·</span>
                {tile.blocked > 0 ? (
                    <DashboardBadge
                        label={`${tile.blocked} blocked`}
                        tone="danger"
                    />
                ) : (
                    <span>{tile.blocked} blocked</span>
                )}
            </div>
        </TileCard>
    );
}
