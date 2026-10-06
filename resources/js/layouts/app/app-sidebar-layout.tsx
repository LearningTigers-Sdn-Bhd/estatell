import { AppContent } from '@/components/app-content';
import { AppNavbar } from '@/components/app-navbar';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    variant = 'default',
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <div className="flex min-h-svh w-full flex-col">
                <AppNavbar />
                <div className="flex min-h-0 flex-1">
                    <AppSidebar />
                    <AppContent
                        variant="sidebar"
                        className={cn(
                            'min-w-0 overflow-x-clip',
                            variant === 'fixed' &&
                                'h-[calc(100svh-3.5rem)] min-h-120 overflow-hidden',
                        )}
                    >
                        <AppSidebarHeader
                            breadcrumbs={breadcrumbs}
                            showTrigger={false}
                        />
                        {children}
                    </AppContent>
                </div>
            </div>
        </AppShell>
    );
}
