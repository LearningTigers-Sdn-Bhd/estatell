import { Breadcrumbs } from '@/components/breadcrumbs';
import { AppearanceToggle } from '@/components/appearance-toggle';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
    showTrigger = true,
    showAppearanceToggle = false,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    showTrigger?: boolean;
    showAppearanceToggle?: boolean;
}) {
    return (
        <header className="flex h-10 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 md:px-4">
            <div className="flex min-w-0 items-center gap-2">
                {showTrigger && <SidebarTrigger className="-ml-1" />}
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {showAppearanceToggle && (
                <div className="ml-auto shrink-0">
                    <AppearanceToggle />
                </div>
            )}
        </header>
    );
}
