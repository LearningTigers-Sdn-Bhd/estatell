import { Link } from '@inertiajs/react';
import { Circle, CircleCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * A section inside a Workspace panel: the title and description get the normal inset (`ps-3 pt-2`), the content
 * sits on the panel's own padding. `action` sits on the right of the heading.
 */
export function PanelSection({
    title,
    description,
    action,
    className,
    children,
}: {
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
    children?: ReactNode;
}) {
    return (
        <section className={cn('flex flex-col gap-4', className)}>
            <header className="flex flex-wrap items-start justify-between gap-3 ps-3 pt-2">
                <div className="flex min-w-0 flex-col gap-1">
                    <h2 className="text-base font-medium">{title}</h2>
                    {description && (
                        <p className="text-sm text-pretty text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {action}
            </header>
            {children}
        </section>
    );
}

/** Several sections in one panel, each divided from the one above. */
export function PanelStack({ children }: { children: ReactNode }) {
    return (
        <div className="flex flex-col gap-6 *:not-first:border-t *:not-first:pt-4">
            {children}
        </div>
    );
}

export function Status({ ready }: { ready: boolean }) {
    const Icon = ready ? CircleCheck : Circle;

    return (
        <Badge variant={ready ? 'secondary' : 'outline'}>
            <Icon data-icon="inline-start" aria-hidden />
            {ready ? 'Ready' : 'Add details'}
        </Badge>
    );
}

/** A section edited on another settings page: what guests see now, and a link to change it. */
export function LinkedSection({
    title,
    body,
    ready,
    href,
    action,
}: {
    title: string;
    body: string;
    ready: boolean;
    href: string;
    action: string;
}) {
    return (
        <PanelSection
            title={title}
            description={body}
            action={<Status ready={ready} />}
        >
            <Link
                href={href}
                className={buttonVariants({
                    variant: 'outline',
                    className: 'min-h-11 self-start',
                })}
            >
                {action}
            </Link>
        </PanelSection>
    );
}
