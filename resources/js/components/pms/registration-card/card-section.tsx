import type { ReactNode } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

/** A titled card of the guest screen. */
export function CardSection({
    title,
    action,
    children,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between gap-2">
                <CardTitle className="font-heading text-base font-semibold">
                    {title}
                </CardTitle>
                {action}
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {children}
            </CardContent>
        </Card>
    );
}
