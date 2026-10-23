import { Diamond } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Bar } from './project-bars';

type Stay = App.Data.StayView.StayData;

const TONE_CLASSES: Record<App.Enums.Tone, string> = {
    neutral: 'border-border bg-muted',
    info: 'border-info bg-info/15',
    success: 'border-success bg-success/15',
    warning: 'border-warning bg-warning/15',
    danger: 'border-destructive bg-destructive/15',
};

/** The words a screen reader reads for a stay bar. */
export function stayLabel(stay: Stay, bar: Bar, dates: string[]): string {
    const guests = `${stay.adults} adult${stay.adults === 1 ? '' : 's'}${stay.children > 0 ? `, ${stay.children} child${stay.children === 1 ? '' : 'ren'}` : ''}`;

    return [
        stay.guestLabel ?? 'No guest named',
        stay.groupName ? `group ${stay.groupName}` : null,
        guests,
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
    bar,
    dates,
    labelWidth,
    columnWidth,
    highlighted,
    onHover,
    onOpen,
}: {
    stay: Stay;
    bar: Bar;
    dates: string[];
    labelWidth: number;
    columnWidth: number;
    highlighted: boolean;
    onHover: (reservationId: number | null) => void;
    onOpen: (unitId: number) => void;
}) {
    const grouped = stay.groupName !== null;

    return (
        <button
            type="button"
            aria-label={stayLabel(stay, bar, dates)}
            title={stayLabel(stay, bar, dates)}
            className={cn(
                'pointer-events-auto absolute top-1.5 bottom-1.5 flex cursor-pointer items-center gap-1 overflow-hidden border-y border-l-4 px-2 text-left text-xs text-foreground outline-none focus-visible:ring-3 focus-visible:ring-ring/50',
                TONE_CLASSES[stay.status.tone],
                bar.openStart ? 'rounded-l-none border-l-0' : 'rounded-l-md',
                bar.openEnd ? 'rounded-r-none' : 'rounded-r-md border-r',
                highlighted && 'ring-2 ring-ring',
            )}
            style={{
                left: labelWidth + bar.start * columnWidth + 2,
                width: bar.span * columnWidth - 4,
            }}
            onClick={() => onOpen(stay.unitId)}
            onMouseEnter={() =>
                grouped ? onHover(stay.reservationId) : undefined
            }
            onMouseLeave={() => (grouped ? onHover(null) : undefined)}
        >
            {grouped && (
                <Diamond aria-hidden className="size-3 shrink-0 fill-current" />
            )}
            <span className="min-w-0 flex-1 truncate">
                {grouped && (
                    <span className="font-medium">{stay.groupName} · </span>
                )}
                {stay.guestLabel ?? 'No guest named'}
                <span className="text-muted-foreground">
                    {' '}
                    · {stay.adults + stay.children}
                    {stay.children > 0 ? 'P' : 'A'}
                </span>
            </span>
            {bar.span >= 2 && (
                <span className="shrink-0 text-muted-foreground">
                    {stay.status.label}
                </span>
            )}
            {stay.balanceDue && (
                <span
                    aria-hidden
                    className="absolute inset-y-0 right-0 w-1 bg-warning"
                />
            )}
        </button>
    );
}
