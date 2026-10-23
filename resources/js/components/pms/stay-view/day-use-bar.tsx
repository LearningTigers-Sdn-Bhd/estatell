import { Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { HoverCard, HoverCardTrigger } from '@/components/ui/hover-card';
import { show } from '@/routes/reservations';
import { DayUseHoverCard } from './bar-hover-card';
import type { DayUseMarker } from './project-bars';

type Room = App.Data.StayView.RoomRowData;

/**
 * The day uses of a room on one date: a marker in the third quarter of the date cell, a clock and a count when
 * there is more than one. The hours are in the hover card and the accessible label, never in the position. With
 * `reservations.view`, a marker of one day use opens its reservation, and the hover card links each day use.
 */
export function DayUseBar({
    marker,
    room,
    labelWidth,
    columnWidth,
    canViewReservations,
}: {
    marker: DayUseMarker;
    /** The row the marker is in, when it is on screen. */
    room: Room | undefined;
    labelWidth: number;
    columnWidth: number;
    canViewReservations: boolean;
}) {
    const count = marker.dayUses.length;
    const hours = marker.dayUses
        .map((dayUse) => `${dayUse.startsAt} to ${dayUse.endsAt}`)
        .join(', ');

    const className =
        'pointer-events-auto absolute inset-y-0 flex flex-col items-center justify-center gap-0.5 overflow-hidden border border-info bg-background text-xs text-foreground tabular-nums outline-none focus-visible:ring-3 focus-visible:ring-ring/50';
    const style = {
        left: labelWidth + (marker.column + 0.5) * columnWidth,
        width: columnWidth / 4,
    };
    const label = `Day use, ${hours}`;

    return (
        <HoverCard>
            <HoverCardTrigger
                delay={300}
                render={
                    canViewReservations && count === 1 ? (
                        <Link
                            href={show.url({
                                reservation: marker.dayUses[0].reservationId,
                            })}
                            aria-label={label}
                            className={className}
                            style={style}
                        />
                    ) : (
                        <div
                            role="img"
                            aria-label={label}
                            className={className}
                            style={style}
                        />
                    )
                }
            >
                <span
                    aria-hidden
                    className="absolute inset-0 -z-10 bg-info/15"
                />
                <Clock aria-hidden className="size-3.5" />
                {count > 1 && <span aria-hidden>{count}</span>}
            </HoverCardTrigger>
            <DayUseHoverCard
                dayUses={marker.dayUses}
                room={room}
                canViewReservations={canViewReservations}
            />
        </HoverCard>
    );
}
