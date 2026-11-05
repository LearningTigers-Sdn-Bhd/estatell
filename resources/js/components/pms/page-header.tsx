import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { buttonVariants } from '@/components/ui/button';

type Props = {
    title: string;
    description?: ReactNode;
    back?: { href: NonNullable<InertiaLinkProps['href']>; label: string };
    badge?: ReactNode;
    /** A row under the description, for example badges. */
    meta?: ReactNode;
    actions?: ReactNode;
};

/**
 * Page title row: optional icon-only back link, then a column with the Geist heading and description,
 * then badge and actions. `meta` adds a row under the description.
 */
export function PageHeader({
    title,
    description,
    back,
    badge,
    meta,
    actions,
}: Props) {
    return (
        <header className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
                {back && (
                    <Link
                        href={back.href}
                        aria-label={`Back to ${back.label}`}
                        className={buttonVariants({
                            variant: 'outline',
                            size: 'icon',
                        })}
                    >
                        <ChevronLeft />
                    </Link>
                )}
                <div className="flex min-w-0 flex-col gap-1">
                    <div className="flex min-w-0 items-center gap-2">
                        <h1 className="truncate font-heading text-xl font-semibold tracking-tight">
                            {title}
                        </h1>
                        {badge}
                    </div>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                    {meta && (
                        <div className="flex flex-wrap items-center gap-2 pt-1">
                            {meta}
                        </div>
                    )}
                </div>
            </div>
            {actions && (
                <div className="flex items-center gap-2">{actions}</div>
            )}
        </header>
    );
}
