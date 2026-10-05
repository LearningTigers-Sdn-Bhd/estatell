import {
    CalendarX,
    Clock,
    Landmark,
    LogOut,
    UserX,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { TabLinks } from '@/components/pms/tab-links';
import { index } from '@/routes/settings/room-revenue';
import { show } from '@/routes/settings/room-revenue/policies';

export type RoomRevenueTab =
    | 'taxes'
    | 'no-show'
    | 'cancellation'
    | 'early-departure'
    | 'late-checkout';

const policyTabs: { value: RoomRevenueTab; label: string; icon: LucideIcon }[] =
    [
        { value: 'no-show', label: 'No-show', icon: UserX },
        { value: 'cancellation', label: 'Cancellation', icon: CalendarX },
        { value: 'early-departure', label: 'Early departure', icon: LogOut },
        { value: 'late-checkout', label: 'Late check-out', icon: Clock },
    ];

/**
 * The five Room revenue tabs. Each is a link to its own URL, so the server sends only the open tab's data.
 */
export function RoomRevenueTabs({
    activeTab,
    children,
}: {
    activeTab: RoomRevenueTab;
    children: ReactNode;
}) {
    return (
        <TabLinks
            activeTab={activeTab}
            panelWidth="full"
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
            ]}
        >
            {children}
        </TabLinks>
    );
}
