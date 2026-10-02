import { Ban, BedDouble, BedSingle, UserX, WandSparkles } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

type Box = App.Data.Reservations.ReservationRoomBoxData;

function iconFor(box: Box): LucideIcon {
    switch (box.state) {
        case 'auto_assign':
            return WandSparkles;
        case 'cancelled':
            return Ban;
        case 'no_show':
            return UserX;
        default:
            return box.isBed ? BedSingle : BedDouble;
    }
}

/** Assigned rooms look normal, a warning marks rooms still to assign, and every other state is muted. */
function toneClass(box: Box): string {
    if (box.state === 'assigned') {
        return 'border-border bg-card text-foreground';
    }

    return box.tone === 'warning'
        ? 'border-warning/30 bg-warning/10 text-warning'
        : 'border-transparent bg-muted text-muted-foreground';
}

/**
 * The room or bed of a reservation: numbers when assigned (two at most, then +N), otherwise an icon with a tooltip.
 * `inline` is the compact form for table rows.
 */
export function RoomBox({
    box,
    inline = false,
}: {
    box: Box;
    inline?: boolean;
}) {
    const Icon = iconFor(box);
    const assigned = box.state === 'assigned';
    const label = assigned
        ? `Rooms ${box.numbers.join(', ')}${box.more > 0 ? ` and ${box.more} more` : ''}`
        : (box.tooltip ?? box.label);

    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <span
                        role="img"
                        aria-label={label}
                        className={cn(
                            'relative inline-flex shrink-0 items-center justify-center rounded-md border text-sm tabular-nums',
                            inline ? 'h-7 gap-1 px-2' : 'size-14 flex-col',
                            toneClass(box),
                        )}
                    />
                }
            >
                {assigned ? (
                    <>
                        {box.numbers.map((number) => (
                            <span
                                key={number}
                                className="leading-tight font-medium"
                            >
                                {number}
                            </span>
                        ))}
                        {box.more > 0 && (
                            <span className="text-xs text-muted-foreground">
                                +{box.more}
                            </span>
                        )}
                    </>
                ) : (
                    <>
                        <Icon aria-hidden className="size-5" />
                        {box.count > 1 && (
                            <span className="absolute -top-1.5 -right-1.5 flex min-w-4 items-center justify-center rounded-full bg-warning px-1 text-[0.625rem] leading-4 font-medium text-background">
                                {box.count}
                            </span>
                        )}
                    </>
                )}
            </TooltipTrigger>
            {(box.tooltip !== null || box.units.length > 0) && (
                <TooltipContent>
                    {box.units.length > 0 ? (
                        <ul className="flex flex-col gap-0.5">
                            {box.units.map((unit, index) => (
                                <li key={index}>
                                    {unit.roomType}: {unit.assigned ?? '—'}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        box.tooltip
                    )}
                </TooltipContent>
            )}
        </Tooltip>
    );
}
