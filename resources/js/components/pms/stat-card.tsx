import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * One headline figure with a label, a muted hint and an icon chip. With `onClick` the card is a toggle button, for
 * example a status filter; `pressed` marks the open one.
 */
export function StatCard({
    icon: Icon,
    label,
    value,
    hint,
    pressed,
    onClick,
}: {
    icon: LucideIcon;
    label: string;
    value: ReactNode;
    hint: ReactNode;
    pressed?: boolean;
    onClick?: () => void;
}) {
    const card = (
        <Card className={cn('h-full', pressed && 'ring-2 ring-primary')}>
            <CardContent className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 flex-col gap-1">
                    <span className="text-sm text-muted-foreground">
                        {label}
                    </span>
                    <span className="font-heading text-2xl font-semibold tracking-tight tabular-nums">
                        {value}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {hint}
                    </span>
                </div>
                <span
                    aria-hidden
                    className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/5 text-primary"
                >
                    <Icon className="size-5" />
                </span>
            </CardContent>
        </Card>
    );

    if (!onClick) {
        return card;
    }

    return (
        <button
            type="button"
            aria-pressed={pressed}
            onClick={onClick}
            className="rounded-xl text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground"
        >
            {card}
        </button>
    );
}
