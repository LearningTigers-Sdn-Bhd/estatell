import type { ReactNode } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * One figure of the property dashboard: a small card with a title, the figure and a line under it.
 */
export function PortalStatTile({
    title,
    figure,
    note,
}: {
    title: string;
    figure: ReactNode;
    note: ReactNode;
}) {
    return (
        <Card size="sm" className="min-w-0">
            <CardHeader>
                <CardTitle className="text-sm font-medium text-muted-foreground">
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
