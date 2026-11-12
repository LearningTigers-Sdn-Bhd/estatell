import type { InertiaLinkProps } from '@inertiajs/react';
import { CalendarRange, ListChecks } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { TabLinks } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';

type Href = NonNullable<InertiaLinkProps['href']>;

/** The Calendar and Bulk edit tabs, for the tab bar and the breadcrumbs. */
export function rateTabs(calendarHref: Href, bulkHref: Href): TabLink[] {
    return [
        {
            value: 'calendar',
            label: 'Calendar',
            icon: CalendarRange,
            href: calendarHref,
        },
        {
            value: 'bulk',
            label: 'Bulk edit',
            icon: ListChecks,
            href: bulkHref,
        },
    ];
}

/**
 * The tabs of the rates pages: the calendar and bulk edit, each with its own URL. Staff who cannot manage rates
 * have no bulk edit, so they see no tabs.
 */
export function RateTabs({
    activeTab,
    calendarHref,
    bulkHref,
    canManage,
    className,
    contentClassName,
    children,
}: {
    activeTab: 'calendar' | 'bulk';
    calendarHref: Href;
    bulkHref: Href;
    canManage: boolean;
    className?: string;
    /** Classes for the content under the tabs. Staff with no tabs get them on the wrapper too. */
    contentClassName?: string;
    children: ReactNode;
}) {
    if (!canManage) {
        return (
            <div className={cn(className, contentClassName)}>{children}</div>
        );
    }

    const tabs = rateTabs(calendarHref, bulkHref);

    return (
        <TabLinks
            tabs={tabs}
            activeTab={activeTab}
            panelWidth="full"
            panelClassName={contentClassName}
            className={className}
        >
            {children}
        </TabLinks>
    );
}
