import { AdminSidebar } from '@/components/admin-sidebar';
import { AdminTwoFactorBanner } from '@/components/admin-two-factor-banner';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

/**
 * Admin shell: sidebar, then the breadcrumb header and content. The document scrolls.
 * `fixed` holds the content to the screen height, for a workspace page whose panels scroll on their own.
 */
export default function AdminLayout({
    children,
    breadcrumbs = [],
    variant = 'default',
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AdminSidebar />
            <AppContent
                variant="sidebar"
                className={cn(
                    'min-w-0',
                    variant === 'fixed'
                        ? 'h-svh overflow-hidden overscroll-none'
                        : 'overflow-x-clip',
                )}
            >
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    showAppearanceToggle
                />
                <AdminTwoFactorBanner />
                {children}
            </AppContent>
        </AppShell>
    );
}
