import { AppContent } from '@/components/app-content';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { AppNavbar } from '@/components/app-navbar';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { StaffWorkspaceHeader } from '@/components/staff-workspace-header';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    variant = 'default',
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <div
                data-staff-shell
                className="flex h-svh w-full flex-col overflow-hidden overscroll-none"
            >
                <AppNavbar surface="sidebar" />
                <StaffWorkspaceHeader breadcrumbs={breadcrumbs} />
                <div className="flex min-h-0 flex-1">
                    <AppSidebar />
                    <AppContent
                        variant="sidebar"
                        className={cn(
                            'min-h-0 min-w-0 overscroll-none',
                            variant === 'fixed'
                                ? 'overflow-hidden'
                                : 'overflow-x-clip overflow-y-auto',
                        )}
                    >
                        <ImpersonationBanner />
                        {children}
                    </AppContent>
                </div>
            </div>
        </AppShell>
    );
}
