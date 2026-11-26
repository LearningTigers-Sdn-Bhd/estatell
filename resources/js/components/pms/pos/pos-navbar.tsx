import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { NavUser } from '@/components/nav-user';
import { PosThemeToggle } from '@/components/pms/pos/pos-theme-toggle';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { buttonVariants } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';

type BackAction = {
    href: string;
    label: string;
};

/**
 * Shared edge-to-edge top bar for full-screen POS views, built on the same sidebar surface and
 * property identity mark as `AppNavbar`.
 */
export function PosNavbar({
    title,
    subtitle,
    titleAs = 'span',
    identityHref,
    back,
    leftExtra,
    center,
    right,
    showUser = false,
    showPropertyAvatar = true,
    signage = false,
}: {
    title?: string;
    subtitle?: string;
    titleAs?: 'h1' | 'span';
    identityHref?: string;
    back?: BackAction | null;
    leftExtra?: ReactNode;
    center?: ReactNode;
    right?: ReactNode;
    showUser?: boolean;
    showPropertyAvatar?: boolean;
    signage?: boolean;
}) {
    const property = usePage().props.property;
    const getInitials = useInitials();

    const headingText = title ?? property?.name ?? 'Point of sale';
    const subText = subtitle ?? (property ? `ID ${property.code}` : undefined);

    const identityContent = (
        <>
            {showPropertyAvatar && (
                <Avatar
                    key={property?.logoUrl ?? headingText}
                    className="size-8 shrink-0 overflow-hidden rounded-md after:rounded-md"
                >
                    <AvatarImage
                        src={property?.logoUrl ?? undefined}
                        alt=""
                        className="rounded-md object-contain"
                    />
                    <AvatarFallback className="rounded-md bg-primary text-xs font-semibold text-primary-foreground">
                        {getInitials(property?.name ?? headingText)}
                    </AvatarFallback>
                </Avatar>
            )}
            <div className="grid min-w-0 text-left leading-tight">
                {titleAs === 'h1' ? (
                    <h1
                        className={cn(
                            'truncate font-heading font-semibold tracking-tight text-sidebar-foreground',
                            signage ? 'text-xl sm:text-2xl' : 'text-base',
                        )}
                    >
                        {headingText}
                    </h1>
                ) : (
                    <span
                        className={cn(
                            'truncate font-heading font-semibold tracking-tight text-sidebar-foreground',
                            signage ? 'text-xl sm:text-2xl' : 'text-sm',
                        )}
                    >
                        {headingText}
                    </span>
                )}
                {subText && (
                    <span className="truncate text-xs text-muted-foreground">
                        {subText}
                    </span>
                )}
            </div>
        </>
    );

    return (
        <header className="shrink-0 border-b border-sidebar-border bg-sidebar text-sidebar-foreground">
            <div
                className={cn(
                    'flex min-h-14 flex-wrap items-center justify-between gap-x-4 gap-y-2 py-1.5',
                    signage ? 'px-6 py-3 lg:px-10' : 'px-4 sm:px-5',
                )}
            >
                <div className="flex min-w-0 items-center gap-2.5">
                    {back && (
                        <>
                            <Link
                                href={back.href}
                                className={buttonVariants({
                                    variant: 'ghost',
                                    className: '-ml-2 min-h-11 px-2.5',
                                })}
                            >
                                <ArrowLeft
                                    data-icon="inline-start"
                                    aria-hidden="true"
                                />
                                <span className="hidden sm:inline">
                                    {back.label}
                                </span>
                                <span className="sr-only sm:hidden">
                                    {back.label}
                                </span>
                            </Link>
                            <span
                                aria-hidden="true"
                                className="h-5 w-px shrink-0 bg-sidebar-border"
                            />
                        </>
                    )}

                    {identityHref ? (
                        <Link
                            href={identityHref}
                            className="flex min-h-11 min-w-0 items-center gap-2.5 rounded-lg px-1.5 py-1 transition-colors hover:bg-sidebar-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground"
                        >
                            {identityContent}
                        </Link>
                    ) : (
                        <div className="flex min-w-0 items-center gap-2.5 py-1">
                            {identityContent}
                        </div>
                    )}

                    {leftExtra}
                </div>

                {center && (
                    <div className="flex items-center justify-center">
                        {center}
                    </div>
                )}

                <div className="flex shrink-0 items-center gap-1">
                    {right}
                    <PosThemeToggle />
                    {showUser && <NavUser />}
                </div>
            </div>
        </header>
    );
}
