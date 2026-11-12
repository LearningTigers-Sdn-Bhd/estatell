import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * One figure of the property dashboard: a small card with a title, the figure and a line under it.
 */
export function PortalStatTile({
    title,
    icon: Icon,
    figure,
    note,
}: {
    title: string;
    icon: LucideIcon;
    figure: ReactNode;
    note: ReactNode;
}) {
    return (
        <Card size="sm" className="min-w-0">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                    <span
                        aria-hidden
                        className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                    >
                        <Icon className="size-5" />
                    </span>
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-1">
                <p className="font-heading text-2xl font-semibold tabular-nums">
                    {figure}
                </p>
                <p className="text-sm text-muted-foreground">{note}</p>
            </CardContent>
        </Card>
    );
}
