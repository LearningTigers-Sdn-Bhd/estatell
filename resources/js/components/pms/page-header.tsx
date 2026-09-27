import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import type { ReactNode } from 'react';

type Props = {
    title: string;
    description?: string;
    back?: { href: NonNullable<InertiaLinkProps['href']>; label: string };
    badge?: ReactNode;
    actions?: ReactNode;
};

/**
 * Page title row: optional back link, Geist heading, badge and actions.
 */
export function PageHeader({
    title,
    description,
    back,
    badge,
    actions,
}: Props) {
    return (
        <header className="flex flex-col gap-2">
            {back && (
                <Link
                    href={back.href}
                    className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ChevronLeft className="size-4" />
                    {back.label}
                </Link>
            )}
            <div className="flex flex-wrap items-center justify-between gap-3">
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
                </div>
                {actions && (
                    <div className="flex items-center gap-2">{actions}</div>
                )}
            </div>
        </header>
    );
}
