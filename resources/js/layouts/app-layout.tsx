import { usePage } from '@inertiajs/react';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';
import { setUrlDefaults } from '@/wayfinder';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { property } = usePage().props;

    // Mirrors URL::defaults in EnsurePropertyAccess, so property routes need no code.
    // Set during render so the sidebar and page below already see it.
    setUrlDefaults({ property: property?.code });

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
        </AppLayoutTemplate>
    );
}
