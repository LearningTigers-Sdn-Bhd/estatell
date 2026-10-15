import { Breadcrumbs } from '@/components/breadcrumbs';
import { AppearanceToggle } from '@/components/appearance-toggle';
import { CommandPalette } from '@/components/pms/command-palette';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
    showTrigger = true,
    showAppearanceToggle = false,
    className,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    showTrigger?: boolean;
    showAppearanceToggle?: boolean;
    className?: string;
}) {
    return (
        <header
            className={cn(
                'flex h-10 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 md:px-4',
                className,
            )}
        >
            <div className="flex min-w-0 items-center gap-2">
                {showTrigger && <SidebarTrigger className="-ml-1" />}
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {showAppearanceToggle && (
                <div className="ml-auto flex shrink-0 items-center gap-1">
                    <CommandPalette />
                    <AppearanceToggle />
                </div>
            )}
        </header>
    );
}
