import { Baby, Diamond, Layers, User } from 'lucide-react';
import { BookingSourceBadge } from '@/components/pms/booking-source-badge';
import { HoverCard, HoverCardTrigger } from '@/components/ui/hover-card';
import { cn } from '@/lib/utils';
import { StayHoverCard } from './bar-hover-card';
import { barBox, type Bar } from './project-bars';

type Stay = App.Data.StayView.StayData;
type Room = App.Data.StayView.RoomRowData;

/** The border and the tint of each tone. The tint is a layer over a solid background, so the grid does not show through. */
const TONE_CLASSES: Record<App.Enums.Tone, { border: string; tint: string }> = {
    neutral: { border: 'border-border', tint: 'bg-muted' },
    info: { border: 'border-info', tint: 'bg-info/15' },
    success: { border: 'border-success', tint: 'bg-success/15' },
    warning: { border: 'border-warning', tint: 'bg-warning/15' },
    danger: { border: 'border-destructive', tint: 'bg-destructive/15' },
};

/** The words a screen reader reads for a stay bar. */
export function stayLabel(stay: Stay, bar: Bar, dates: string[]): string {
    const guests = `${stay.adults} adult${stay.adults === 1 ? '' : 's'}${stay.children > 0 ? `, ${stay.children} child${stay.children === 1 ? '' : 'ren'}` : ''}`;

    return [
        stay.guestLabel ?? 'No guest named',
        stay.groupName ? `group ${stay.groupName}` : null,
        `via ${stay.source.name}`,
        guests,
        stay.unitCount > 1 ? `${stay.unitCount} units` : null,
        stay.status.label,
        `${dates[bar.start]} to ${dates[bar.start + bar.span - 1]}`,
        stay.balanceDue ? 'balance due' : null,
    ]
        .filter(Boolean)
        .join(', ');
}

/**
 * One segment of a stay, drawn over the cells of its row. The status label is always in the text, so colour never
 * carries meaning alone. A group booking has a marker and the group name; the other bars of the group light up when
 * one is hovered.
 */
export function StayBar({
    stay,
    room,
    bar,
    dates,
    labelWidth,
    columnWidth,
    highlighted,
    onHover,
    onOpen,
}: {
    stay: Stay;
    /** The row the bar is in, when it is on screen. */
    room: Room | undefined;
    bar: Bar;
    dates: string[];
    labelWidth: number;
    columnWidth: number;
    highlighted: boolean;
    onHover: (reservationId: number | null) => void;
    onOpen: (unitId: number) => void;
}) {
    const grouped = stay.groupName !== null;
    const tone = TONE_CLASSES[stay.status.tone];

    return (
        <HoverCard>
            <HoverCardTrigger
                delay={300}
                render={
                    <button
                        type="button"
                        aria-label={stayLabel(stay, bar, dates)}
                        className={cn(
                            'pointer-events-auto absolute inset-y-0 isolate flex cursor-pointer flex-col justify-center gap-0.5 overflow-hidden border-y border-l-4 bg-background px-2 text-left text-xs text-foreground outline-none focus-visible:ring-3 focus-visible:ring-ring/50',
                            tone.border,
                            bar.openStart && 'border-l-0',
                            !bar.openEnd && 'border-r',
                            highlighted && 'ring-2 ring-ring',
                        )}
                        style={barBox(bar, labelWidth, columnWidth)}
                        onClick={() => onOpen(stay.unitId)}
                        onMouseEnter={() =>
                            grouped ? onHover(stay.reservationId) : undefined
                        }
                        onMouseLeave={() =>
                            grouped ? onHover(null) : undefined
                        }
                    />
                }
            >
                <span
                    aria-hidden
                    className={cn('absolute inset-0 -z-10', tone.tint)}
                />
                {bar.openStart && (
                    <span
                        aria-hidden
                        className={cn(
                            'absolute inset-y-0 left-0 border-l-2 border-dashed',
                            tone.border,
                        )}
                    />
                )}
                <span className="flex min-w-0 items-center gap-1">
                    <BookingSourceBadge
                        source={stay.source}
                        size="sm"
                        tooltip={false}
                        className="rounded-none"
                    />
                    {grouped && (
                        <Diamond
                            aria-hidden
                            className="size-3 shrink-0 fill-current"
                        />
                    )}
                    <span className="min-w-0 flex-1 truncate">
                        {grouped && (
                            <span className="font-medium">
                                {stay.groupName} ·{' '}
                            </span>
                        )}
                        {stay.guestLabel ?? 'No guest named'}
                    </span>
                    {bar.span >= 2 && (
                        <span className="shrink-0 text-muted-foreground">
                            {stay.status.label}
                        </span>
                    )}
                </span>
                <span
                    aria-hidden
                    className="flex items-center gap-2 pl-6 text-muted-foreground tabular-nums"
                >
                    <span className="flex items-center gap-0.5">
                        <User className="size-3" />
                        {stay.adults}
                    </span>
                    {stay.children > 0 && (
                        <span className="flex items-center gap-0.5">
                            <Baby className="size-3" />
                            {stay.children}
                        </span>
                    )}
                    {stay.unitCount > 1 && (
                        <span className="flex items-center gap-0.5">
                            <Layers className="size-3" />
                            {stay.unitCount}
                        </span>
                    )}
                </span>
                {stay.balanceDue && (
                    <span
                        aria-hidden
                        className="absolute inset-y-0 right-0 w-1 bg-warning"
                    />
                )}
            </HoverCardTrigger>
            <StayHoverCard stay={stay} room={room} />
        </HoverCard>
    );
}
