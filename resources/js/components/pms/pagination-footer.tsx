import { Link, router } from '@inertiajs/react';
import {
    ChevronFirst,
    ChevronLast,
    ChevronLeft,
    ChevronRight,
} from 'lucide-react';
import { useId, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { paginationPage, paginationUrl } from './pagination-navigation';

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
 * Shared navigation and page jump for server-paginated tables and card grids.
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
            className="flex flex-col-reverse items-center justify-between gap-3 text-sm text-muted-foreground md:flex-row"
        >
            <p className="text-center tabular-nums">
                Showing {pagination.from}–{pagination.to} of {pagination.total}
            </p>
            {pagination.lastPage > 1 && (
                <PaginationControls
                    key={`${pagination.currentPage}-${pagination.lastPage}-${pagination.prevUrl}-${pagination.nextUrl}`}
                    pagination={pagination}
                />
            )}
        </nav>
    );
}

function PaginationControls({ pagination }: { pagination: PaginationData }) {
    const inputId = useId();
    const [page, setPage] = useState(String(pagination.currentPage));
    const [error, setError] = useState(false);
    const baseUrl = pagination.prevUrl ?? pagination.nextUrl;

    function jumpToPage(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const target = paginationPage(page, pagination.lastPage);

        if (target === null) {
            setError(true);
            return;
        }

        setError(false);

        if (target !== pagination.currentPage && baseUrl) {
            router.visit(paginationUrl(baseUrl, target), {
                preserveScroll: true,
                preserveState: true,
            });
        }
    }

    return (
        <div className="flex flex-col items-center gap-2">
            <div className="flex items-center gap-2">
                <div className="flex items-center gap-1">
                    <PageLink
                        href={
                            pagination.prevUrl && baseUrl
                                ? paginationUrl(baseUrl, 1)
                                : null
                        }
                        label="First page"
                    >
                        <ChevronFirst data-icon="inline-start" />
                        <span className="hidden md:inline">First</span>
                    </PageLink>
                    <PageLink
                        href={pagination.prevUrl}
                        label="Previous page"
                        rel="prev"
                    >
                        <ChevronLeft data-icon="inline-start" />
                        <span className="hidden md:inline">Previous</span>
                    </PageLink>
                </div>
                <form
                    onSubmit={jumpToPage}
                    noValidate
                    className="flex items-center gap-2 whitespace-nowrap tabular-nums"
                >
                    <label htmlFor={inputId} className="sr-only md:not-sr-only">
                        Page
                    </label>
                    <Input
                        id={inputId}
                        name="page"
                        aria-label="Page number"
                        aria-invalid={error || undefined}
                        aria-describedby={
                            error ? `${inputId}-error` : `${inputId}-hint`
                        }
                        inputMode="numeric"
                        enterKeyHint="go"
                        autoComplete="off"
                        value={page}
                        onChange={(event) => {
                            setPage(event.target.value);
                            setError(false);
                        }}
                        className="w-12 text-center"
                    />
                    <span>of {pagination.lastPage}</span>
                    <span id={`${inputId}-hint`} className="sr-only">
                        Press Enter to go to this page.
                    </span>
                    <button type="submit" className="sr-only" tabIndex={-1}>
                        Go to page
                    </button>
                </form>
                <div className="flex items-center gap-1">
                    <PageLink
                        href={pagination.nextUrl}
                        label="Next page"
                        rel="next"
                    >
                        <span className="hidden md:inline">Next</span>
                        <ChevronRight data-icon="inline-end" />
                    </PageLink>
                    <PageLink
                        href={
                            pagination.nextUrl && baseUrl
                                ? paginationUrl(baseUrl, pagination.lastPage)
                                : null
                        }
                        label="Last page"
                    >
                        <span className="hidden md:inline">Last</span>
                        <ChevronLast data-icon="inline-end" />
                    </PageLink>
                </div>
            </div>
            {error && (
                <p id={`${inputId}-error`} role="alert">
                    Enter a whole number from 1 to {pagination.lastPage}.
                </p>
            )}
        </div>
    );
}

function PageLink({
    href,
    label,
    rel,
    children,
}: {
    href: string | null;
    label: string;
    rel?: 'prev' | 'next';
    children: ReactNode;
}) {
    if (!href) {
        return (
            <span
                aria-disabled
                aria-label={label}
                className={cn(
                    buttonVariants({ variant: 'outline' }),
                    'pointer-events-none size-8 opacity-50 md:w-auto',
                )}
            >
                {children}
            </span>
        );
    }

    return (
        <Link
            href={href}
            aria-label={label}
            rel={rel}
            preserveScroll
            preserveState
            className={cn(
                buttonVariants({ variant: 'outline' }),
                'size-8 md:w-auto',
            )}
        >
            {children}
        </Link>
    );
}
