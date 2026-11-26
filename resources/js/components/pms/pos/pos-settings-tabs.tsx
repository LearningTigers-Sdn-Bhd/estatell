import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { index as posApiClientsIndex } from '@/routes/settings/pos-api-clients';
import { index as posOutletsIndex } from '@/routes/settings/pos-outlets';
import { index as nativeDiningIndex } from '@/routes/settings/pos/dining-options';
import { index as nativeFloorsIndex } from '@/routes/settings/pos/floors';
import { index as nativeInventoryIndex } from '@/routes/settings/pos/inventory';
import { index as nativeKitchenIndex } from '@/routes/settings/pos/kitchen';
import { index as nativeMenuIndex } from '@/routes/settings/pos/menu';
import { index as nativeModifiersIndex } from '@/routes/settings/pos/modifiers';
import { index as nativeOutletsIndex } from '@/routes/settings/pos/outlets';
import { index as nativeSchedulesIndex } from '@/routes/settings/pos/schedules';
import { index as nativeStaffIndex } from '@/routes/settings/pos/staff-pins';
import type { BreadcrumbItem } from '@/types';

export type PosSettingsTab =
    | 'outlets'
    | 'api'
    | 'registers'
    | 'menu'
    | 'modifiers'
    | 'dining'
    | 'floors'
    | 'kitchen'
    | 'inventory'
    | 'schedules'
    | 'staff';

const pageMeta: Record<
    PosSettingsTab,
    { title: string; description: string; href: () => BreadcrumbItem['href'] }
> = {
    outlets: {
        title: 'Outlets',
        description:
            'Restaurants, bars, and spas that sell from the register or post bills to guest rooms.',
        href: () => posOutletsIndex(),
    },
    api: {
        title: 'API access',
        description:
            'External POS API tokens and outlet scopes for third-party room charge postings.',
        href: () => posApiClientsIndex(),
    },
    registers: {
        title: 'Registers & accounts',
        description:
            'Cash registers, payment methods, rounding, customer display, and folio posting codes per outlet.',
        href: () => nativeOutletsIndex(),
    },
    menu: {
        title: 'Menu',
        description:
            'Categories, items, variants, pricing, barcodes, and per-outlet availability.',
        href: () => nativeMenuIndex(),
    },
    modifiers: {
        title: 'Modifiers',
        description:
            'Add-ons, preparation choices, required selections, and option availability.',
        href: () => nativeModifiersIndex(),
    },
    dining: {
        title: 'Dining options',
        description:
            'Dine-in, takeaway, delivery, and room service options with service charge and packaging rules.',
        href: () => nativeDiningIndex(),
    },
    floors: {
        title: 'Floor plan',
        description:
            'Dining rooms, table layouts, seating counts, and table numbers for pay-later service.',
        href: () => nativeFloorsIndex(),
    },
    kitchen: {
        title: 'Kitchen stations',
        description:
            'KDS stations, prep time thresholds, and category routing per outlet.',
        href: () => nativeKitchenIndex(),
    },
    inventory: {
        title: 'Inventory & recipes',
        description:
            'Ingredients, dish recipes, deliveries, wastage, transfers, and stocktakes.',
        href: () => nativeInventoryIndex(),
    },
    schedules: {
        title: 'Schedules & combos',
        description:
            'Time-of-day menu schedules and bundle slot rules for combo items.',
        href: () => nativeSchedulesIndex(),
    },
    staff: {
        title: 'Staff & terminals',
        description:
            'Staff PIN invitations, registered browser terminals, and void reasons.',
        href: () => nativeStaffIndex(),
    },
};

export function posSettingsBreadcrumbs(
    activeTab: PosSettingsTab,
): BreadcrumbItem[] {
    const meta = pageMeta[activeTab];

    return [{ title: meta.title, href: meta.href() }];
}

export function PosSettingsShell({
    activeTab,
    actions,
    meta,
    children,
}: {
    activeTab: PosSettingsTab;
    actions?: ReactNode;
    meta?: ReactNode;
    children: ReactNode;
}) {
    const metaInfo = pageMeta[activeTab];

    return (
        <>
            <Head title={`${metaInfo.title} · POS`} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={metaInfo.title}
                    description={metaInfo.description}
                    meta={meta}
                    actions={actions}
                />

                <div className="flex flex-col gap-6">{children}</div>
            </div>
        </>
    );
}
