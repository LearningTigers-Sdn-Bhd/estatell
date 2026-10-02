import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export type PaginationData = {
    currentPage: number;
    lastPage: number;
    from: number | null;
    to: number | null;
    total: number;
    prevUrl: string | null;
    nextUrl: string | null;
};

/**
 * Pagination meta from a Laravel paginator in the shape `PaginationFooter` takes.
 */
export function toPaginationData(meta: {
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}): PaginationData {
    return {
        currentPage: meta.current_page,
        lastPage: meta.last_page,
        from: meta.from,
        to: meta.to,
        total: meta.total,
        prevUrl: meta.prev_page_url,
        nextUrl: meta.next_page_url,
    };
}

/**
 * "Showing 1–24 of 312" with Previous and Next links, for a server-paginated list. Shared by the data table and card grids.
 */
export function PaginationFooter({
    pagination,
}: {
    pagination: PaginationData;
}) {
    if (pagination.total === 0) {
        return null;
    }

    return (
        <nav
            aria-label="Pagination"
            className="flex items-center justify-between gap-3 text-sm text-muted-foreground"
        >
            <p className="tabular-nums">
                Showing {pagination.from}–{pagination.to} of {pagination.total}
            </p>
            {pagination.lastPage > 1 && (
                <div className="flex items-center gap-2">
                    <PageLink href={pagination.prevUrl}>Previous</PageLink>
                    <span className="tabular-nums">
                        Page {pagination.currentPage} of {pagination.lastPage}
                    </span>
                    <PageLink href={pagination.nextUrl}>Next</PageLink>
                </div>
            )}
        </nav>
    );
}

function PageLink({
    href,
    children,
}: {
    href: string | null;
    children: ReactNode;
}) {
    if (!href) {
        return (
            <span
                aria-disabled
                className={cn(
                    buttonVariants({ variant: 'outline', size: 'sm' }),
                    'pointer-events-none opacity-50',
                )}
            >
                {children}
            </span>
        );
    }

    return (
        <Link
            href={href}
            preserveScroll
            preserveState
            className={buttonVariants({ variant: 'outline', size: 'sm' })}
        >
            {children}
        </Link>
    );
}
