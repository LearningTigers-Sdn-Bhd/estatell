import { PartnerSidebar } from '@/components/partner-sidebar';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

/**
 * Partner shell: sidebar, then the breadcrumb header and content. The document scrolls.
 * `fixed` holds the content to the screen height, for a workspace page whose panels scroll on their own.
 */
export default function PartnerLayout({
    children,
    breadcrumbs = [],
    variant = 'default',
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <PartnerSidebar />
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
                {children}
            </AppContent>
        </AppShell>
    );
}
