import { cn } from '@/lib/utils';
import type { Bar } from './project-bars';

/** A room or bed taken out of sale, drawn as a hatched bar with its reason. */
export function BlockBar({
    bar,
    reason,
    labelWidth,
    columnWidth,
}: {
    bar: Bar;
    reason: string;
    labelWidth: number;
    columnWidth: number;
}) {
    return (
        <div
            role="img"
            aria-label={`Blocked, ${reason}`}
            title={`Blocked: ${reason}`}
            className={cn(
                'pointer-events-auto absolute top-1.5 bottom-1.5 flex items-center overflow-hidden rounded-md border border-border bg-[repeating-linear-gradient(135deg,transparent_0_6px,var(--color-border)_6px_12px)] px-2 text-xs text-muted-foreground',
                bar.openStart && 'rounded-l-none border-l-0',
                bar.openEnd && 'rounded-r-none border-r-0',
            )}
            style={{
                left: labelWidth + bar.start * columnWidth + 2,
                width: bar.span * columnWidth - 4,
            }}
        >
            <span className="truncate rounded-sm bg-background/80 px-1">
                {reason}
            </span>
        </div>
    );
}
