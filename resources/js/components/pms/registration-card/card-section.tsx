import type { ReactNode } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

/** A titled card of the guest screen. */
export function CardSection({
    title,
    action,
    fill = false,
    children,
}: {
    title: string;
    action?: ReactNode;
    /** Grow to the height of the column, so the last child can take the free space. */
    fill?: boolean;
    children: ReactNode;
}) {
    return (
        <Card className={fill ? 'flex-1' : undefined}>
            <CardHeader className="flex-row items-center justify-between gap-2">
                <CardTitle className="font-heading text-base font-semibold">
                    {title}
                </CardTitle>
                {action}
            </CardHeader>
            <CardContent
                className={
                    fill
                        ? 'flex min-h-0 flex-1 flex-col gap-4'
                        : 'flex flex-col gap-4'
                }
            >
                {children}
            </CardContent>
        </Card>
    );
}
