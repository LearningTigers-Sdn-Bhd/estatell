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
    tone = 'neutral',
}: {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    children: ReactNode;
    className?: string;
    icon?: LucideIcon;
    tone?: 'neutral' | 'primary' | 'soft-primary' | 'warning';
}) {
    return (
        <Card size="sm" className={cn('relative h-full min-w-0', className)}>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    {Icon && (
                        <span
                            aria-hidden
                            className={cn(
                                'flex size-8 shrink-0 items-center justify-center rounded-lg',
                                {
                                    'bg-muted text-muted-foreground':
                                        tone === 'neutral',
                                    'bg-primary/10 text-primary':
                                        tone === 'primary',
                                    'bg-primary/5 text-primary':
                                        tone === 'soft-primary',
                                    'bg-warning/10 text-warning-text':
                                        tone === 'warning',
                                },
                            )}
                        >
                            <Icon className="size-5" />
                        </span>
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
