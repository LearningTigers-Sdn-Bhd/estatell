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
    parentDiscounts,
    currency,
    ageGroups,
    preview,
}: {
    ratePlan: RatePlan;
    discounts: App.Data.Rates.RatePlanStayDiscountData[];
    discountTypes: { value: string; label: string }[];
    parentDiscounts: App.Data.Rates.RatePlanParentDiscountsData | null;
    currency: App.Data.Property.CurrencyData;
    ageGroups: App.Data.Property.PropertyAgeGroupData[];
    preview: App.Data.Rates.StayDiscountPreviewData;
}) {
    return (
        <>
            <Head title={`${ratePlan.name} discounts`} />
            <RatePlanShell
                ratePlan={ratePlan}
                activeTab="discounts"
                panelWidth="full"
            >
                <DiscountsForm
                    ratePlan={ratePlan}
                    discounts={discounts}
                    types={discountTypes}
                    parentDiscounts={parentDiscounts}
                    currency={currency}
                    ageGroups={ageGroups}
                    preview={preview}
                />
            </RatePlanShell>
        </>
    );
}

RatePlanDiscounts.layout = (props: { ratePlan: RatePlan }) => ({
    breadcrumbs: ratePlanBreadcrumbs(props.ratePlan),
});
