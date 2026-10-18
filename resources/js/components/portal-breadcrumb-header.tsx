import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { BreadcrumbItem } from '@/types';

/**
 * Breadcrumb row of the company portal shell: the staff workspace row without the workspace label column.
 * See docs/design-system/09-app-shell.md.
 */
export function PortalBreadcrumbHeader({
    breadcrumbs,
}: {
    breadcrumbs: BreadcrumbItem[];
}) {
    return (
        <div className="flex h-10 shrink-0 border-b border-sidebar-border bg-sidebar text-sidebar-foreground">
            <AppSidebarHeader
                breadcrumbs={breadcrumbs}
                showTrigger={false}
                className="min-w-0 flex-1 border-b-0 px-4"
            />
        </div>
    );
}
