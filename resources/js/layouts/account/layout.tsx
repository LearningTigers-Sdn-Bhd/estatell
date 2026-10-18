import { AccountSidebar } from '@/components/account-sidebar';
import { AdminTwoFactorBanner } from '@/components/admin-two-factor-banner';
import { AppContent } from '@/components/app-content';
import { AppNavbar } from '@/components/app-navbar';
import { AppShell } from '@/components/app-shell';
import { DemoBanner } from '@/components/demo-banner';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import type { AppLayoutProps } from '@/types';

export default function AccountLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <div className="flex h-svh w-full flex-col overflow-hidden">
                <DemoBanner />
                <AppNavbar identity="app" />
                <div className="flex min-h-0 flex-1">
                    <AccountSidebar />
                    <AppContent
                        variant="sidebar"
                        className="min-h-0 min-w-0 overflow-x-clip overflow-y-auto"
                    >
                        <ImpersonationBanner />
                        <AppSidebarHeader
                            breadcrumbs={breadcrumbs}
                            showTrigger={false}
                        />
                        <AdminTwoFactorBanner />
                        <div className="px-4 py-6">
                            <section className="flex max-w-xl flex-col gap-12">
                                {children}
                            </section>
                        </div>
                    </AppContent>
                </div>
            </div>
        </AppShell>
    );
}
