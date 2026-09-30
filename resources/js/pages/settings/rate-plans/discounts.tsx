import { Head } from '@inertiajs/react';
import { DiscountsForm } from '@/components/pms/rate-plans/discounts-form';
import {
    RatePlanShell,
    ratePlanBreadcrumbs,
} from '@/components/pms/rate-plans/rate-plan-shell';

type RatePlan = App.Data.Rates.RatePlanData;

export default function RatePlanDiscounts({
    ratePlan,
    discounts,
    discountTypes,
    currency,
}: {
    ratePlan: RatePlan;
    discounts: App.Data.Rates.RatePlanStayDiscountData[];
    discountTypes: { value: string; label: string }[];
    currency: App.Enums.Currency;
}) {
    return (
        <>
            <Head title={`${ratePlan.name} discounts`} />
            <RatePlanShell ratePlan={ratePlan} activeTab="discounts">
                <DiscountsForm
                    ratePlan={ratePlan}
                    discounts={discounts}
                    types={discountTypes}
                    currency={currency}
                />
            </RatePlanShell>
        </>
    );
}

RatePlanDiscounts.layout = (props: { ratePlan: RatePlan }) => ({
    breadcrumbs: ratePlanBreadcrumbs(props.ratePlan),
});
