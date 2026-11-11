import { ReceiptText, Rows3 } from 'lucide-react';
import type { ReactNode } from 'react';
import { TabLinks } from '@/components/pms/tab-links';
import { index } from '@/routes/admin/commissions';
import { index as vouchers } from '@/routes/admin/commissions/vouchers';

/**
 * The Bookings and Vouchers tabs of the admin Commissions page. Each tab is its own URL.
 */
export function CommissionTabs({
    activeTab,
    children,
}: {
    activeTab: 'bookings' | 'vouchers';
    children: ReactNode;
}) {
    return (
        <TabLinks
            activeTab={activeTab}
            panelWidth="full"
            tabs={[
                {
                    value: 'bookings',
                    label: 'Bookings',
                    icon: Rows3,
                    href: index(),
                },
                {
                    value: 'vouchers',
                    label: 'Vouchers',
                    icon: ReceiptText,
                    href: vouchers(),
                },
            ]}
        >
            {children}
        </TabLinks>
    );
}
