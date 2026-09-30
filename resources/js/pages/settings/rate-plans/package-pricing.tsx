import { Head } from '@inertiajs/react';
import { PackagePricingForm } from '@/components/pms/rate-plans/package-pricing-form';
import {
    RatePlanShell,
    ratePlanBreadcrumbs,
} from '@/components/pms/rate-plans/rate-plan-shell';

type RatePlan = App.Data.Rates.RatePlanData;

export default function RatePlanPackagePricing({
    ratePlan,
    roomTypes,
    prices,
    weekdays,
    weekdayOptions,
    currency,
}: {
    ratePlan: RatePlan;
    roomTypes: App.Data.Rates.PackagePriceRoomTypeData[];
    prices: App.Data.Rates.PackagePriceData[];
    weekdays: string[];
    weekdayOptions: { value: string; label: string; shortLabel: string }[];
    currency: App.Enums.Currency;
}) {
    return (
        <>
            <Head title={`${ratePlan.name} pricing`} />
            <RatePlanShell ratePlan={ratePlan} activeTab="pricing">
                <PackagePricingForm
                    ratePlan={ratePlan}
                    roomTypes={roomTypes}
                    prices={prices}
                    weekdays={weekdays}
                    weekdayOptions={weekdayOptions}
                    currency={currency}
                />
            </RatePlanShell>
        </>
    );
}

RatePlanPackagePricing.layout = (props: { ratePlan: RatePlan }) => ({
    breadcrumbs: ratePlanBreadcrumbs(props.ratePlan),
});
