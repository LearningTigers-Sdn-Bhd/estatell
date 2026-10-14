import { Head } from '@inertiajs/react';
import { ChildPricesForm } from '@/components/pms/rate-plans/child-prices-form';
import {
    RatePlanShell,
    ratePlanBreadcrumbs,
} from '@/components/pms/rate-plans/rate-plan-shell';

type RatePlan = App.Data.Rates.RatePlanData;

export default function RatePlanChildPrices({
    ratePlan,
    childPrices,
    ageBandPricings,
    currency,
}: {
    ratePlan: RatePlan;
    childPrices: App.Data.Rates.RatePlanChildPriceData[];
    ageBandPricings: { value: string; label: string }[];
    currency: App.Data.Property.CurrencyData;
}) {
    return (
        <>
            <Head title={`${ratePlan.name} child prices`} />
            <RatePlanShell ratePlan={ratePlan} activeTab="children">
                <ChildPricesForm
                    ratePlan={ratePlan}
                    childPrices={childPrices}
                    pricings={ageBandPricings}
                    currency={currency}
                />
            </RatePlanShell>
        </>
    );
}

RatePlanChildPrices.layout = (props: { ratePlan: RatePlan }) => ({
    breadcrumbs: ratePlanBreadcrumbs(props.ratePlan),
});
