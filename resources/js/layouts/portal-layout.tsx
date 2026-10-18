import { AppContent } from '@/components/app-content';
import { AppNavbar } from '@/components/app-navbar';
import { AppShell } from '@/components/app-shell';
import { DemoBanner } from '@/components/demo-banner';
import { PortalBreadcrumbHeader } from '@/components/portal-breadcrumb-header';
import { PortalSidebar } from '@/components/portal-sidebar';
import { index } from '@/routes/portal';
import type { AppLayoutProps } from '@/types';

/**
 * Company portal shell: navbar, breadcrumb row, then sidebar and content. Same three rows as the staff shell.
 */
export default function PortalLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <div
                data-portal-shell
                className="flex h-svh w-full flex-col overflow-hidden overscroll-none"
            >
                <DemoBanner />
                <AppNavbar
                    identity="app"
                    surface="sidebar"
                    palette={false}
                    logoHref={index().url}
                />
                <PortalBreadcrumbHeader breadcrumbs={breadcrumbs} />
                <div className="flex min-h-0 flex-1">
                    <PortalSidebar />
                    <AppContent
                        variant="sidebar"
                        className="min-h-0 min-w-0 overflow-x-clip overflow-y-auto overscroll-none"
                    >
                        {children}
                    </AppContent>
                </div>
            </div>
        </AppShell>
    );
}
