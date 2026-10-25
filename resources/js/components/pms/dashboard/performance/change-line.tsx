import { cn } from 'cn';
import type { Change } from '@/components/pms/dashboard/performance/format';

export function ChangeLine({
    change,
    neutral = false,
}: {
    change: Change | null;
    neutral?: boolean;
}) {
    if (change === null) {
        return (
            <p className="text-xs text-muted-foreground">
                Nothing to compare with
            </p>
        );
    }

    return (
        <p
            className={cn(
                'w-fit rounded-md px-1.5 py-0.5 text-xs font-medium tabular-nums',
                neutral || change.direction === 'same'
                    ? 'bg-muted text-muted-foreground'
                    : change.direction === 'up'
                      ? 'bg-primary/10 text-primary'
                      : 'bg-warning/10 text-warning-text',
            )}
        >
            {change.text}
        </p>
    );
}
