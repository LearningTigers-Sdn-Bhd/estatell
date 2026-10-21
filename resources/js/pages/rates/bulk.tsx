import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarRange, Info } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import { RateTabs } from '@/components/pms/rate-calendar/rate-tabs';
import {
    BulkEditForm,
    BulkPlanPicker,
} from '@/components/pms/rate-plans/bulk-edit-form';
import { nextPlanSelection } from '@/components/pms/rate-plans/bulk-price-model';
import type { SavedPrices } from '@/components/pms/rate-plans/bulk-price-model';
import { PricingForm } from '@/components/pms/rate-plans/pricing-form';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { buttonVariants } from '@/components/ui/button';
import { calendar } from '@/routes/rates';
import { bulk } from '@/routes/rates/calendar';
import { update as bulkUpdate } from '@/routes/rates/calendar/bulk';
import { index as ratePlansIndex } from '@/routes/settings/rate-plans';

type Plan = App.Data.Rates.RateBulkPlanData;

function planNote(plan: Plan): string | null {
    if (plan.derived) {
        return `${plan.name} follows ${plan.followsName}, so its price comes from that plan. Only restrictions can change here.`;
    }

    if (!plan.hasOwnPrices) {
        return 'Package prices are set on the package. Only restrictions can change here.';
    }

    return null;
}

/**
 * Bulk edit: a full page, because it has a price table. Pick rate plans, room types and a date range, then change
 * prices and restrictions over the whole range. Saving returns to the calendar at the start of the range. A Day use
 * plan is edited on its own, with the plan's own pricing form.
 */
export default function RateBulkEdit({
    plans,
    ratePlan,
    roomTypes,
    currency,
    weekendNights,
    weekendNightsOptions,
    today,
    from,
    to,
    saved,
}: {
    plans: Plan[];
    ratePlan: App.Data.Rates.RatePlanData | null;
    roomTypes: App.Data.Rates.RatePricingRoomTypeData[];
    currency: App.Data.Property.CurrencyData;
    weekendNights: string;
    weekendNightsOptions: App.Data.Property.WeekendNightsOptionData[];
    today: string;
    from: string;
    to: string;
    saved: SavedPrices;
}) {
    const selected = plans.find((plan) => plan.id === ratePlan?.id);
    const dayUse = selected?.pricingBasis === 'day_use';
    const note = selected ? planNote(selected) : null;
    const query = from && to ? { from, to } : {};
    const [planIds, setPlanIds] = useState<string[]>(
        ratePlan ? [String(ratePlan.id)] : [],
    );

    const openPlan = (id: string) =>
        router.get(bulk.url(undefined, { query: { plan: id, ...query } }));

    const changePlans = (next: string[]) => {
        const resolved = nextPlanSelection(plans, planIds, next);
        const picked = plans.find((plan) => String(plan.id) === resolved[0]);

        if (
            resolved.length > 0 &&
            (picked?.pricingBasis === 'day_use' || dayUse)
        ) {
            openPlan(resolved[0]);

            return;
        }

        setPlanIds(resolved);
    };

    const header = (
        <PageHeader
            title="Bulk edit rates"
            description="Change prices and restrictions over a date range."
        />
    );

    return (
        <div className="flex min-h-0 flex-1 flex-col pt-4">
            <Head title="Bulk edit rates" />
            <RateTabs
                activeTab="bulk"
                calendarHref={calendar.url(undefined, { query })}
                bulkHref={bulk.url(undefined, {
                    query: {
                        ...(ratePlan ? { plan: ratePlan.id } : {}),
                        ...query,
                    },
                })}
                canManage
                className="flex min-h-0 flex-1 flex-col gap-4 [&>[data-slot=tabs-list]]:mx-4"
                contentClassName="flex min-h-0 flex-1 flex-col"
            >
                {!ratePlan || !selected ? (
                    <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 [&>*]:shrink-0">
                        {header}
                        <EmptyState
                            icon={CalendarRange}
                            title="No rate plans"
                            description="Add a rate plan that sells at least one room type first."
                            action={
                                <Link
                                    href={ratePlansIndex.url()}
                                    className={buttonVariants()}
                                >
                                    Go to rate plans
                                </Link>
                            }
                        />
                    </div>
                ) : dayUse ? (
                    <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 [&>*]:shrink-0">
                        {header}
                        <div className="w-full sm:max-w-sm">
                            <BulkPlanPicker
                                plans={plans}
                                value={[String(ratePlan.id)]}
                                onValueChange={changePlans}
                            />
                        </div>
                        {note && (
                            <Alert>
                                <Info />
                                <AlertDescription>{note}</AlertDescription>
                            </Alert>
                        )}
                        {roomTypes.length === 0 ? (
                            <EmptyState
                                title="No room types"
                                description="Choose the room types this plan sells before you change its rates."
                            />
                        ) : (
                            <PricingForm
                                key={ratePlan.id}
                                ratePlan={ratePlan}
                                roomTypes={roomTypes}
                                currency={currency}
                                weekendNights={weekendNights}
                                weekendNightsOptions={weekendNightsOptions}
                                today={today}
                                saveUrl={bulkUpdate.url({
                                    ratePlan: ratePlan.id,
                                })}
                                initialRange={
                                    from && to ? { from, to } : undefined
                                }
                                restrictions
                                prices={selected.hasOwnPrices}
                                submitLabel="Save changes"
                            />
                        )}
                    </div>
                ) : (
                    <BulkEditForm
                        header={header}
                        cancelHref={calendar.url(undefined, { query })}
                        plans={plans}
                        planIds={planIds}
                        onPlanIdsChange={changePlans}
                        currency={currency}
                        weekendNights={weekendNights}
                        weekendNightsOptions={weekendNightsOptions}
                        today={today}
                        initialRange={from && to ? { from, to } : undefined}
                        saved={saved}
                    />
                )}
            </RateTabs>
        </div>
    );
}

RateBulkEdit.layout = {
    variant: 'fixed',
    breadcrumbs: [
        { title: 'Rate calendar', href: calendar() },
        { title: 'Bulk edit', href: bulk() },
    ],
};
