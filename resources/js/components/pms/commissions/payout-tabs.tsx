import { ReceiptText, Rows3 } from 'lucide-react';
import type { ReactNode } from 'react';
import { TabLinks } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { index } from '@/routes/finance/payouts';
import { index as vouchers } from '@/routes/finance/payouts/vouchers';

/** The Bookings and Payouts tabs, for the tab bar and the breadcrumbs. */
export function payoutTabs(): TabLink[] {
    return [
        {
            value: 'bookings',
            label: 'Bookings',
            icon: Rows3,
            href: index(),
        },
        {
            value: 'payouts',
            label: 'Payouts',
            icon: ReceiptText,
            href: vouchers(),
        },
    ];
}

/**
 * The Bookings and Payouts tabs of the property Payouts page. Each tab is its own URL.
 */
export function PayoutTabs({
    activeTab,
    children,
}: {
    activeTab: 'bookings' | 'payouts';
    children: ReactNode;
}) {
    return (
        <TabLinks activeTab={activeTab} panelWidth="full" tabs={payoutTabs()}>
            {children}
        </TabLinks>
    );
}
