import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

/**
 * `default` lets the page grow and the window scroll. `fixed` fixes the page to the window height: the page fills
 * it and a region inside scrolls on its own (the Grid calendar and full-page Form archetypes).
 */
export type PageLayoutVariant = 'default' | 'fixed';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    variant?: PageLayoutVariant;
};

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
    /** More lines under the message, one reason on each line. */
    description?: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
