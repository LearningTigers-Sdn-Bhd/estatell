import { Progress } from '@/components/ui/progress';
import type { InventoryDay } from './summarize-inventory';

/** A small bar with a track that shows on the grid's muted backgrounds, where the default muted track vanishes. */
const BAR_CLASS =
    'w-10 gap-0 [&_[data-slot=progress-track]]:h-1.5 [&_[data-slot=progress-track]]:bg-foreground/15';

/** The label of the footer: what its cells count. */
export function InventoryFooterLabel() {
    return (
        <span className="text-xs font-semibold tracking-wide text-grid-header-foreground uppercase">
            Available · Occupancy
        </span>
    );
}

/** One date of the footer: the percent occupied as a bar, and the rooms or beds still free of the total. */
export function InventoryFooterCell({
    day,
}: {
    day: InventoryDay | undefined;
}) {
    if (!day) {
        return null;
    }

    return (
        <div
            title={`${day.available} available of ${day.total}, ${day.occupied} occupied, ${day.blocked} blocked`}
            className="flex h-full flex-col items-center justify-center gap-0.5 pb-2 text-xs tabular-nums"
        >
            <Progress
                value={day.occupancy}
                aria-label={`Occupancy ${day.occupancy}%`}
                className={BAR_CLASS}
            />
            <span className="text-sm font-semibold">{day.occupancy}%</span>
            <span className="text-grid-muted-foreground">
                {day.available}/{day.total}
            </span>
        </div>
    );
}
