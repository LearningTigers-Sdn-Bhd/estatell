import { Link } from '@inertiajs/react';
import { Clock, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { BookingSourceBadge } from '@/components/pms/booking-source-badge';
import { HoverCardContent } from '@/components/ui/hover-card';
import { cn } from '@/lib/utils';
import { show } from '@/routes/reservations';
import { PaxCount } from './pax-count';
import { dayAfter, dayLabel, nightCount, placeLabel } from './stay-places';

type Room = App.Data.StayView.RoomRowData;
type Stay = App.Data.StayView.StayData;
type Block = App.Data.StayView.BlockData;
type DayUse = App.Data.StayView.DayUseData;

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[4.5rem_1fr] items-baseline gap-2">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0">{children}</dd>
        </div>
    );
}

function Heading({
    icon,
    title,
    lines,
}: {
    icon: ReactNode;
    title: string;
    lines: string[];
}) {
    return (
        <div className="flex items-center gap-2.5">
            {icon}
            <div className="flex min-w-0 flex-col">
                <span className="truncate text-base font-medium">{title}</span>
                {lines.map((line) => (
                    <span
                        key={line}
                        className="truncate text-sm text-muted-foreground"
                    >
                        {line}
                    </span>
                ))}
            </div>
        </div>
    );
}

/**
 * What a stay bar hides: the guest, the booking and its source, the status, the stay, the room, how many guests and
 * whether money is due. It reads the loaded stay, so it opens at once. The folio stays in the peek sheet.
 */
export function StayHoverCard({
    stay,
    room,
    unassigned = false,
}: {
    stay: Stay;
    room: Room | undefined;
    /** The bar is in the Unassigned lane: the nights have no room yet. */
    unassigned?: boolean;
}) {
    const nights = nightCount(stay.arrivalDate, stay.departureDate);

    return (
        <HoverCardContent
            side="bottom"
            align="start"
            className="flex w-80 flex-col gap-2 p-2.5"
        >
            <Heading
                icon={
                    <BookingSourceBadge
                        source={stay.source}
                        tooltip={false}
                        className="size-10 rounded-none [&_svg]:size-5"
                    />
                }
                title={stay.guestLabel ?? 'No guest named'}
                lines={[
                    `${stay.reference} · ${stay.unitCount} ${stay.unitCount === 1 ? 'unit' : 'units'}`,
                    `via ${stay.source.name}`,
                ]}
            />
            <dl className="flex flex-col gap-1 rounded-lg bg-muted p-2 text-sm">
                <Detail label="Status">{stay.status.label}</Detail>
                <Detail label="Stay">
                    <span className="tabular-nums">
                        {dayLabel(stay.arrivalDate)} –{' '}
                        {dayLabel(stay.departureDate)}
                    </span>
                    <span className="text-muted-foreground">
                        {' '}
                        · {nights} {nights === 1 ? 'night' : 'nights'}
                    </span>
                </Detail>
                {room && <Detail label="Room">{placeLabel(room)}</Detail>}
                {unassigned && <Detail label="Room">Not assigned</Detail>}
                <Detail label="Guests">
                    <PaxCount stay={stay} />
                </Detail>
                <Detail label="Payment">
                    <span
                        className={cn(stay.balanceDue && 'text-warning-text')}
                    >
                        {stay.balanceDue ? 'Balance due' : 'Nothing due'}
                    </span>
                </Detail>
            </dl>
        </HoverCardContent>
    );
}

/** What a block bar hides: why the room is out of sale, for which nights, and which room. */
export function BlockHoverCard({
    block,
    reason,
    room,
}: {
    block: Block | undefined;
    reason: string;
    room: Room | undefined;
}) {
    const nights = block
        ? nightCount(block.firstNight, block.lastNight) + 1
        : 0;

    return (
        <HoverCardContent
            side="bottom"
            align="start"
            className="flex w-72 flex-col gap-2 p-2.5"
        >
            <Heading
                icon={
                    <span
                        aria-hidden
                        className="flex size-10 shrink-0 items-center justify-center bg-muted text-muted-foreground"
                    >
                        <Lock className="size-5" />
                    </span>
                }
                title="Blocked"
                lines={[reason]}
            />
            <dl className="flex flex-col gap-1 rounded-lg bg-muted p-2 text-sm">
                {block && (
                    <Detail label="Dates">
                        <span className="tabular-nums">
                            {dayLabel(block.firstNight)} –{' '}
                            {dayLabel(dayAfter(block.lastNight))}
                        </span>
                        <span className="text-muted-foreground">
                            {' '}
                            · {nights} {nights === 1 ? 'night' : 'nights'}
                        </span>
                    </Detail>
                )}
                {room && <Detail label="Room">{placeLabel(room)}</Detail>}
            </dl>
        </HoverCardContent>
    );
}

/** What a day use marker hides: each day use of the room on that date, with its hours and guest. */
export function DayUseHoverCard({
    dayUses,
    room,
    canViewReservations,
}: {
    dayUses: DayUse[];
    room: Room | undefined;
    canViewReservations: boolean;
}) {
    return (
        <HoverCardContent
            side="bottom"
            align="start"
            className="flex w-72 flex-col gap-2 p-2.5"
        >
            <Heading
                icon={
                    <span
                        aria-hidden
                        className="flex size-10 shrink-0 items-center justify-center bg-muted text-muted-foreground"
                    >
                        <Clock className="size-5" />
                    </span>
                }
                title={
                    dayUses.length === 1
                        ? 'Day use'
                        : `${dayUses.length} day uses`
                }
                lines={room ? [placeLabel(room)] : []}
            />
            <dl className="flex flex-col gap-1 rounded-lg bg-muted p-2 text-sm">
                {dayUses.map((dayUse) => (
                    <Detail
                        key={dayUse.unitId}
                        label={`${dayUse.startsAt}–${dayUse.endsAt}`}
                    >
                        <span className="block truncate">
                            {dayUse.guestLabel ?? 'No guest named'}
                        </span>
                        <span className="block truncate text-muted-foreground">
                            {canViewReservations ? (
                                <Link
                                    href={show.url({
                                        reservation: dayUse.reservationId,
                                    })}
                                    className="underline underline-offset-2"
                                >
                                    {dayUse.reference}
                                </Link>
                            ) : (
                                dayUse.reference
                            )}{' '}
                            · {dayUse.status.label}
                        </span>
                    </Detail>
                ))}
            </dl>
        </HoverCardContent>
    );
}
