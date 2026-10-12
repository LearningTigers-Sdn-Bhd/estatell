import { Ban, BedDouble, BedSingle, UserX, WandSparkles } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { DateText } from '@/components/pms/date-text';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
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
 * The room or bed of a reservation: numbers when assigned (two at most, then +N), otherwise an icon. Hovering opens a
 * card with what is happening: the note for the state and every unit with its room or bed, status and dates.
 * `inline` is the compact form for table rows. `href` is for the card, where a stretched link covers the box.
 */
export function RoomBox({
    box,
    inline = false,
    href,
}: {
    box: Box;
    inline?: boolean;
    /** Makes the box a link, above the stretched link of a card, so it still opens the reservation and can be hovered. */
    href?: string;
}) {
    const Icon = iconFor(box);
    const assigned = box.state === 'assigned';
    const label = assigned
        ? `Rooms ${box.numbers.join(', ')}${box.more > 0 ? ` and ${box.more} more` : ''}`
        : (box.tooltip ?? box.label);

    const classes = cn(
        'relative inline-flex shrink-0 items-center justify-center rounded-md border text-sm tabular-nums',
        inline ? 'h-7 gap-1 px-2' : 'size-14 flex-col',
        toneClass(box),
    );

    return (
        <HoverCard>
            <HoverCardTrigger
                delay={150}
                render={
                    href === undefined ? (
                        <span
                            role="img"
                            aria-label={label}
                            className={classes}
                        />
                    ) : (
                        <Link
                            href={href}
                            aria-label={label}
                            className={cn(classes, 'z-10')}
                        />
                    )
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
            </HoverCardTrigger>
            {(box.tooltip !== null || box.units.length > 0) && (
                <HoverCardContent
                    align="start"
                    className="flex w-72 flex-col gap-2"
                >
                    {box.tooltip !== null && (
                        <p className="font-medium">{box.tooltip}</p>
                    )}
                    {box.units.length > 0 && (
                        <ul className="flex max-h-64 flex-col gap-2 overflow-y-auto">
                            {box.units.map((unit, index) => (
                                <li
                                    key={index}
                                    className="flex flex-col gap-1 border-t pt-2 first:border-t-0 first:pt-0"
                                >
                                    <span className="flex items-center justify-between gap-2">
                                        <span className="truncate font-medium">
                                            {unit.roomType}
                                        </span>
                                        <StatusBadge
                                            status={unit.status}
                                            dot={false}
                                        />
                                    </span>
                                    <span className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                        <span>
                                            {unit.assigned ?? 'Not assigned'}
                                        </span>
                                        <span className="tabular-nums">
                                            <DateText date={unit.arrivalDate} />{' '}
                                            →{' '}
                                            <DateText
                                                date={unit.departureDate}
                                            />
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </HoverCardContent>
            )}
        </HoverCard>
    );
}
