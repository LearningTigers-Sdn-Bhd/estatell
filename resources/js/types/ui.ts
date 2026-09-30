import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

/**
 * `default` lets the page grow and the window scroll. `grid-only` fixes the page to the window height: the page
 * fills it and a grid inside scrolls on its own (the Grid calendar archetype).
 */
export type PageLayoutVariant = 'default' | 'grid-only';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    variant?: PageLayoutVariant;
};

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
