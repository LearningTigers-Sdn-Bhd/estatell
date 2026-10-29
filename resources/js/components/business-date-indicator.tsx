import { usePage } from '@inertiajs/react';
import {
    CalendarClock,
    CalendarDays,
    LoaderCircle,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    SidebarMenu,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useBusinessDate } from '@/hooks/use-business-date';
import { formatBusinessDay } from '@/lib/business-date';
import type { BusinessDateState, BusinessDateView } from '@/lib/business-date';
import { cn } from '@/lib/utils';

const stateIcon: Record<BusinessDateState, LucideIcon> = {
    'in-sync': CalendarDays,
    'audit-pending': TriangleAlert,
    auditing: LoaderCircle,
    overdue: TriangleAlert,
    ahead: TriangleAlert,
    'not-live': CalendarClock,
};

const stateTone: Record<BusinessDateState, string> = {
    'in-sync': 'text-muted-foreground',
    'audit-pending': 'text-warning',
    auditing: 'text-info',
    overdue: 'text-destructive',
    ahead: 'text-destructive',
    'not-live': 'text-muted-foreground',
};

const stateDot: Record<BusinessDateState, string> = {
    'in-sync': 'bg-muted-foreground',
    'audit-pending': 'bg-warning',
    auditing: 'bg-info',
    overdue: 'bg-destructive',
    ahead: 'bg-destructive',
    'not-live': 'bg-muted-foreground',
};

function systemLine(view: BusinessDateView): string | null {
    if (view.systemDay === null) {
        return null;
    }

    return `${formatBusinessDay(view.systemDay, 'short')} · ${view.systemTime}`;
}

/**
 * Sidebar row r4: the business date next to the system date, so staff always know which day they post to.
 * See docs/design-system/09-app-shell.md. The link to the night audit page comes with that page.
 */
export function BusinessDateIndicator() {
    const { property, navigation } = usePage().props;
    const view = useBusinessDate();
    const { state: sidebarState, isMobile } = useSidebar();

    if (!property || !navigation) {
        return null;
    }

    const Icon = stateIcon[view.state];
    const system = systemLine(view);

    if (sidebarState === 'collapsed' && !isMobile) {
        return (
            <SidebarMenu>
                <SidebarMenuItem>
                    <Tooltip>
                        <TooltipTrigger
                            render={
                                <div
                                    tabIndex={0}
                                    role="status"
                                    aria-label={`Business date. ${view.message}`}
                                    className="relative flex h-8 items-center justify-center rounded-md text-xs font-medium tabular-nums outline-hidden focus-visible:ring-2 focus-visible:ring-sidebar-ring"
                                />
                            }
                        >
                            {view.businessDay
                                ? formatBusinessDay(view.businessDay, 'compact')
                                : '—'}
                            <span
                                aria-hidden
                                className={cn(
                                    'absolute top-0.5 right-0.5 size-1.5 rounded-full',
                                    stateDot[view.state],
                                )}
                            />
                        </TooltipTrigger>
                        <TooltipContent side="right" align="center">
                            <div className="grid gap-0.5">
                                {view.businessDay && (
                                    <span>
                                        Business{' '}
                                        {formatBusinessDay(view.businessDay)}
                                    </span>
                                )}
                                {system && <span>System {system}</span>}
                                <span>{view.message}</span>
                            </div>
                        </TooltipContent>
                    </Tooltip>
                </SidebarMenuItem>
            </SidebarMenu>
        );
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <div
                    role="status"
                    className="grid gap-1 rounded-md px-2 py-1.5 text-xs"
                >
                    <div
                        className={cn(
                            'flex items-center gap-1.5 font-medium',
                            stateTone[view.state],
                        )}
                    >
                        <Icon
                            aria-hidden
                            className={cn(
                                'size-3.5 shrink-0',
                                view.state === 'auditing' && 'animate-spin',
                            )}
                        />
                        <span>{view.message}</span>
                    </div>
                    {view.businessDay ? (
                        <>
                            <div className="flex items-baseline justify-between gap-2">
                                <span className="text-muted-foreground">
                                    Business
                                </span>
                                <span className="font-semibold tabular-nums">
                                    {formatBusinessDay(view.businessDay)}
                                </span>
                            </div>
                            <div className="flex items-baseline justify-between gap-2 text-muted-foreground">
                                <span>System</span>
                                <span className="tabular-nums">{system}</span>
                            </div>
                        </>
                    ) : null}
                </div>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
