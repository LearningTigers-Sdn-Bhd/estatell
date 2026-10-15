import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from 'cn';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { LucideIcon } from 'lucide-react';

/**
 * One cell of the dashboard grid: a card whose title is a link to the page behind the figures.
 */
export function TileCard({
    title,
    href,
    children,
    className,
    icon: Icon,
}: {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    children: ReactNode;
    className?: string;
    icon?: LucideIcon;
}) {
    return (
        <Card className={cn('relative', className)}>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    {Icon && (
                        <Icon
                            aria-hidden
                            className="size-4 shrink-0 text-muted-foreground"
                        />
                    )}
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
