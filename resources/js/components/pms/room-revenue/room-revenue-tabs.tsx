import {
    CalendarX,
    Clock,
    Hourglass,
    Landmark,
    LogOut,
    UserX,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { TabLinks } from '@/components/pms/tab-links';
import { index } from '@/routes/settings/room-revenue';
import { show as showDayUse } from '@/routes/settings/room-revenue/day-use';
import { show } from '@/routes/settings/room-revenue/policies';

export type RoomRevenueTab =
    | 'taxes'
    | 'no-show'
    | 'cancellation'
    | 'early-departure'
    | 'late-checkout'
    | 'day-use';

const policyTabs: { value: RoomRevenueTab; label: string; icon: LucideIcon }[] =
    [
        { value: 'no-show', label: 'No-show', icon: UserX },
        { value: 'cancellation', label: 'Cancellation', icon: CalendarX },
        { value: 'early-departure', label: 'Early departure', icon: LogOut },
        { value: 'late-checkout', label: 'Late check-out', icon: Clock },
    ];

/**
 * The Room revenue tabs: five, and a sixth Day use tab when the property has a Day use plan. Each is a link to its own URL, so the server sends only the open tab's data.
 */
export function RoomRevenueTabs({
    activeTab,
    hasDayUse,
    children,
}: {
    activeTab: RoomRevenueTab;
    /** Whether the property has a Day use plan, which adds the Day use tab. */
    hasDayUse: boolean;
    children: ReactNode;
}) {
    return (
        <TabLinks
            activeTab={activeTab}
            panelWidth="full"
            scrollable
            className="flex flex-col gap-6"
            tabs={[
                {
                    value: 'taxes',
                    label: 'Taxes',
                    icon: Landmark,
                    href: index(),
                },
                ...policyTabs.map((tab) => ({
                    ...tab,
                    href: show({ tab: tab.value }),
                })),
                ...(hasDayUse
                    ? [
                          {
                              value: 'day-use',
                              label: 'Day use',
                              icon: Hourglass,
                              href: showDayUse(),
                          },
                      ]
                    : []),
            ]}
        >
            {children}
        </TabLinks>
    );
}
