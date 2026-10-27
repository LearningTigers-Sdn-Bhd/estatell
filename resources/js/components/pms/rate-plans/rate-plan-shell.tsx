import {
    Baby,
    Bed,
    CalendarDays,
    CircleDollarSign,
    Building2,
    FileText,
    PackagePlus,
    Percent,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Badge } from '@/components/ui/badge';
import { edit, index } from '@/routes/settings/rate-plans';
import { edit as editChildPrices } from '@/routes/settings/rate-plans/child-prices';
import { edit as editDiscounts } from '@/routes/settings/rate-plans/discounts';
import { edit as editInclusions } from '@/routes/settings/rate-plans/inclusions';
import { edit as editCompanies } from '@/routes/settings/rate-plans/companies';
import { edit as editPricing } from '@/routes/settings/rate-plans/pricing';
import { edit as editRoomTypes } from '@/routes/settings/rate-plans/room-types';
import { show as showSavedPrices } from '@/routes/settings/rate-plans/saved-prices';

type RatePlan = App.Data.Rates.RatePlanData;

export type RatePlanTab =
    | 'details'
    | 'room-types'
    | 'pricing'
    | 'saved-prices'
    | 'children'
    | 'inclusions'
    | 'discounts'
    | 'companies';

export function ratePlanBreadcrumbs(ratePlan: RatePlan) {
    return [
        { title: 'Rate plans', href: index() },
        { title: ratePlan.name, href: edit({ ratePlan: ratePlan.id }) },
    ];
}

/**
 * Header and tab links of the plan editor. Each tab is its own page and URL, and loads only its own data.
 */
export function RatePlanShell({
    ratePlan,
    activeTab,
    panelWidth,
    children,
}: {
    ratePlan: RatePlan;
    activeTab: RatePlanTab;
    panelWidth?: 'default' | 'full';
    children: ReactNode;
}) {
    const tabs: TabLink[] = [
        {
            value: 'details',
            label: 'Details',
            icon: FileText,
            href: edit.url({ ratePlan: ratePlan.id }),
        },
        {
            value: 'room-types',
            label: 'Room types',
            icon: Bed,
            href: editRoomTypes.url({ ratePlan: ratePlan.id }),
        },
        {
            value: 'pricing',
            label: 'Pricing rules',
            icon: CircleDollarSign,
            href: editPricing.url({ ratePlan: ratePlan.id }),
        },
        {
            value: 'saved-prices',
            label: 'Saved prices',
            icon: CalendarDays,
            href: showSavedPrices.url({ ratePlan: ratePlan.id }),
        },
        ...(ratePlan.usesChildPrices
            ? [
                  {
                      value: 'children',
                      label: (
                          <>
                              Children
                              {ratePlan.checkChildPrices && (
                                  <Badge variant="outline">Check</Badge>
                              )}
                          </>
                      ),
                      icon: Baby,
                      href: editChildPrices.url({ ratePlan: ratePlan.id }),
                  },
              ]
            : []),
        {
            value: 'inclusions',
            label: 'Inclusions',
            icon: PackagePlus,
            href: editInclusions.url({ ratePlan: ratePlan.id }),
        },
        ...(ratePlan.pricingBasis !== 'package' &&
        ratePlan.pricingBasis !== 'day_use'
            ? [
                  {
                      value: 'discounts',
                      label: 'Discounts',
                      icon: Percent,
                      href: editDiscounts.url({ ratePlan: ratePlan.id }),
                  },
              ]
            : []),
        ...(ratePlan.pricingBasis !== 'day_use'
            ? [
                  {
                      value: 'companies',
                      label: 'Companies',
                      icon: Building2,
                      href: editCompanies.url({ ratePlan: ratePlan.id }),
                  },
              ]
            : []),
    ];

    return (
        <div className="flex flex-1 flex-col gap-6 p-4">
            <PageHeader
                title={ratePlan.name}
                back={{ href: index.url(), label: 'Rate plans' }}
                description={
                    ratePlan.pricingBasis === 'day_use'
                        ? `Sells ${ratePlan.family.toLowerCase()} types by the hour, ${ratePlan.dayUseMinHours} to ${ratePlan.dayUseMaxHours} hours. Front desk only.`
                        : ratePlan.pricingBasis === 'package'
                          ? `Sells ${ratePlan.family.toLowerCase()} types. Package of ${ratePlan.fixedNights} ${ratePlan.fixedNights === 1 ? 'night' : 'nights'}, charged ${ratePlan.packageUnit?.replace('_', ' ')}.`
                          : `Sells ${ratePlan.family.toLowerCase()} types. Priced ${ratePlan.pricingBasis.replace('_', ' ')}.`
                }
                badge={
                    ratePlan.archived ? (
                        <Badge variant="secondary">Archived</Badge>
                    ) : undefined
                }
            />
            <TabLinks
                tabs={tabs}
                activeTab={activeTab}
                panelWidth={panelWidth}
                className="flex flex-col gap-4"
            >
                {children}
            </TabLinks>
        </div>
    );
}
