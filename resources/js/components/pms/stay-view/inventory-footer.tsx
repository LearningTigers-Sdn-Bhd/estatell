import type { InventoryDay } from './summarize-inventory';

/** The label of the footer: what its two lines count. */
export function InventoryFooterLabel() {
    return (
        <div className="flex flex-col text-xs text-grid-muted-foreground">
            <span>Available</span>
            <span>Occupancy</span>
        </div>
    );
}

/** One date of the footer: rooms or beds still free of the total, and the percent occupied. */
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
            className="flex h-full flex-col items-center justify-center text-xs tabular-nums"
        >
            <span className="font-medium">
                {day.available}/{day.total}
            </span>
            <span className="text-grid-muted-foreground">{day.occupancy}%</span>
        </div>
    );
}
