import { usePage } from '@inertiajs/react';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { useSidebar } from '@/components/ui/sidebar';
import { navIcon } from '@/lib/navigation';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

export function StaffWorkspaceHeader({
    breadcrumbs,
}: {
    breadcrumbs: BreadcrumbItem[];
}) {
    const { navigation } = usePage().props;
    const { state } = useSidebar();
    const current = navigation
        ? (navigation.contexts.find(
              (context) => context.key === navigation.active,
          ) ?? navigation.contexts[0])
        : undefined;
    const Icon = current ? navIcon(current.icon) : undefined;

    return (
        <div className="flex shrink-0 flex-col border-b border-sidebar-border bg-sidebar text-sidebar-foreground md:h-10 md:flex-row">
            <div
                title={current?.label}
                className={cn(
                    'flex h-10 shrink-0 items-center gap-2 border-b border-sidebar-border px-4 text-sm font-medium transition-all duration-200 ease-linear md:border-r md:border-b-0',
                    state === 'collapsed'
                        ? 'md:w-(--sidebar-width-icon) md:justify-center md:px-0'
                        : 'md:w-(--sidebar-width)',
                )}
            >
                {Icon && <Icon aria-hidden className="size-4 shrink-0" />}
                <span
                    className={cn(
                        'truncate',
                        state === 'collapsed' && 'md:sr-only',
                    )}
                >
                    {current?.label}
                </span>
            </div>
            <AppSidebarHeader
                breadcrumbs={breadcrumbs}
                showTrigger={false}
                className="min-w-0 flex-1 border-b-0"
            />
        </div>
    );
}
