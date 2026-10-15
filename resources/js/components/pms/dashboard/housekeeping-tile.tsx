import { Brush } from 'lucide-react';
import { TileCard } from '@/components/pms/dashboard/tile-card';
import { index } from '@/routes/housekeeping';

export function HousekeepingTile({
    tile,
}: {
    tile: App.Data.Dashboard.HousekeepingTileData;
}) {
    return (
        <TileCard title="Housekeeping" icon={Brush} href={index.url()}>
            <div className="grid grid-cols-3 gap-3 tabular-nums">
                <div>
                    <p className="font-heading text-2xl font-semibold">
                        {tile.dirty}
                    </p>
                    <p className="text-sm text-muted-foreground">Dirty</p>
                </div>
                <div>
                    <p className="font-heading text-2xl font-semibold">
                        {tile.toInspect}
                    </p>
                    <p className="text-sm text-muted-foreground">To inspect</p>
                </div>
                <div>
                    <p className="font-heading text-2xl font-semibold">
                        {tile.blocked}
                    </p>
                    <p className="text-sm text-muted-foreground">Blocked</p>
                </div>
            </div>
        </TileCard>
    );
}
