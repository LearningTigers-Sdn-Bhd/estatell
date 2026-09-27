import { AdminTwoFactorBanner } from '@/components/admin-two-factor-banner';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            <AdminTwoFactorBanner />
            {children}
        </AppLayoutTemplate>
    );
}
