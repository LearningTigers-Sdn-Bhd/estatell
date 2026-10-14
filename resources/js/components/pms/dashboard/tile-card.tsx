import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from 'cn';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * One cell of the dashboard grid: a card whose title is a link to the page behind the figures.
 */
export function TileCard({
    title,
    href,
    children,
    className,
}: {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    children: ReactNode;
    className?: string;
}) {
    return (
        <Card className={cn('relative', className)}>
            <CardHeader>
                <CardTitle>
                    <Link
                        href={href}
                        className="rounded-sm outline-none after:absolute after:inset-0 hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        {title}
                    </Link>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-1">
                {children}
            </CardContent>
        </Card>
    );
}
