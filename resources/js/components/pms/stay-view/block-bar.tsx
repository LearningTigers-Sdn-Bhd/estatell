import { HoverCard, HoverCardTrigger } from '@/components/ui/hover-card';
import { cn } from '@/lib/utils';
import { BlockHoverCard } from './bar-hover-card';
import { barBox, type Bar } from './project-bars';

/** A room or bed taken out of sale, drawn as a hatched bar with its reason. */
export function BlockBar({
    bar,
    block,
    room,
    reason,
    labelWidth,
    columnWidth,
}: {
    bar: Bar;
    block: App.Data.StayView.BlockData | undefined;
    /** The row the bar is in, when it is on screen. */
    room: App.Data.StayView.RoomRowData | undefined;
    reason: string;
    labelWidth: number;
    columnWidth: number;
}) {
    return (
        <HoverCard>
            <HoverCardTrigger
                delay={300}
                render={
                    <div
                        role="img"
                        aria-label={`Blocked, ${reason}`}
                        className={cn(
                            'pointer-events-auto absolute inset-y-0 flex items-center overflow-hidden border border-border bg-background bg-[repeating-linear-gradient(135deg,transparent_0_6px,var(--color-border)_6px_12px)] px-2 text-xs text-muted-foreground',
                            bar.openStart && 'border-l-0',
                            bar.openEnd && 'border-r-0',
                        )}
                        style={barBox(bar, labelWidth, columnWidth)}
                    />
                }
            >
                <span className="truncate bg-background/80 px-1">{reason}</span>
            </HoverCardTrigger>
            <BlockHoverCard block={block} reason={reason} room={room} />
        </HoverCard>
    );
}
