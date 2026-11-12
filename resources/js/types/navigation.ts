import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

/** One choice in a crumb's switch menu: another record or tab at the same level. */
export type BreadcrumbSibling = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    current: boolean;
};

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    /** Shows a chevron after the crumb that opens a menu of these choices, headed by `label`. */
    siblings?: {
        label: string;
        items: BreadcrumbSibling[];
    };
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Opens a sheet instead of going to `href`. */
    action?: string | null;
    /** A count shown beside the title. Left out when null. */
    badge?: number | null;
};
