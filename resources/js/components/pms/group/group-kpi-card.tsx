import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export type KpiChange = { text: string; direction: 'up' | 'down' | 'same' };

/**
 * One headline figure of the group dashboard: the label with its icon, the value, a muted line under it, and the
 * change against last year as a chip (green up, warning down).
 */
export function GroupKpiCard({
    label,
    icon: Icon,
    value,
    note,
    change,
}: {
    label: string;
    icon: LucideIcon;
    value: ReactNode;
    note?: ReactNode;
    change?: KpiChange | null;
}) {
    return (
        <Card size="sm" className="gap-0">
            <CardContent className="flex flex-col gap-2">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <span
                        aria-hidden
                        className="flex size-7 items-center justify-center rounded-lg bg-primary/5 text-primary"
                    >
                        <Icon className="size-4" />
                    </span>
                    {label}
                </div>
                <div className="text-2xl font-semibold tracking-tight tabular-nums">
                    {value}
                </div>
                <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    {change && (
                        <span
                            className={cn(
                                'rounded-md px-1.5 py-0.5 font-medium tabular-nums',
                                change.direction === 'up' &&
                                    'bg-primary/10 text-primary',
                                change.direction === 'down' &&
                                    'bg-warning/10 text-warning-text',
                                change.direction === 'same' &&
                                    'bg-muted text-muted-foreground',
                            )}
                        >
                            {change.text}
                        </span>
                    )}
                    {note}
                </div>
            </CardContent>
        </Card>
    );
}
