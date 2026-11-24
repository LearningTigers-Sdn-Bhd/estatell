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
        <div className="flex h-10 shrink-0 border-b border-sidebar-border bg-sidebar text-sidebar-foreground">
            <div
                title={current?.label}
                className={cn(
                    'hidden h-10 shrink-0 items-center border-r border-sidebar-border px-2 text-sm font-medium transition-[width] duration-200 ease-linear md:flex',
                    state === 'collapsed'
                        ? 'md:w-(--sidebar-width-icon)'
                        : 'md:w-(--sidebar-width)',
                )}
            >
                <div
                    className={cn(
                        'flex w-full items-center gap-2 overflow-hidden p-2 transition-[width] duration-200 ease-linear',
                        state === 'collapsed' && 'md:w-8',
                    )}
                >
                    {Icon && <Icon aria-hidden className="size-4 shrink-0" />}
                    <span className="truncate">{current?.label}</span>
                </div>
            </div>
            <AppSidebarHeader
                breadcrumbs={breadcrumbs}
                showTrigger={false}
                className="min-w-0 flex-1 border-b-0 px-4"
            />
        </div>
    );
}
