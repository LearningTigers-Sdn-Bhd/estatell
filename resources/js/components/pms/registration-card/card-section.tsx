import type { ReactNode } from 'react';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

/** A titled card of the guest screen. */
export function CardSection({
    title,
    description,
    action,
    fill = false,
    children,
}: {
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    /** Grow to the height of the column, so the last child can take the free space. */
    fill?: boolean;
    children: ReactNode;
}) {
    return (
        <Card className={fill ? 'flex-1' : undefined}>
            <CardHeader>
                <CardTitle className="font-heading text-base font-semibold">
                    {title}
                </CardTitle>
                {description && (
                    <CardDescription className="tabular-nums">
                        {description}
                    </CardDescription>
                )}
                {action && <CardAction>{action}</CardAction>}
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
