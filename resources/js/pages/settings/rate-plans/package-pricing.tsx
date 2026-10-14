import { Head } from '@inertiajs/react';
import { PackagePricingForm } from '@/components/pms/rate-plans/package-pricing-form';
import { PricingSource } from '@/components/pms/rate-plans/pricing-source';
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
    derivation,
    derivations,
    currency,
}: {
    ratePlan: RatePlan;
    roomTypes: App.Data.Rates.PackagePriceRoomTypeData[];
    prices: App.Data.Rates.PackagePriceData[];
    weekdays: string[];
    weekdayOptions: { value: string; label: string; shortLabel: string }[];
    derivation: App.Data.Rates.RatePlanDerivationData;
    derivations: { value: string; label: string }[];
    currency: App.Data.Property.CurrencyData;
}) {
    const form = (following: boolean) => (
        <PackagePricingForm
            ratePlan={ratePlan}
            roomTypes={roomTypes}
            prices={prices}
            weekdays={weekdays}
            weekdayOptions={weekdayOptions}
            currency={currency}
            following={following}
        />
    );

    return (
        <>
            <Head title={`${ratePlan.name} pricing`} />
            <RatePlanShell ratePlan={ratePlan} activeTab="pricing">
                {roomTypes.length === 0 ? (
                    form(false)
                ) : (
                    <PricingSource
                        ratePlan={ratePlan}
                        derivation={derivation}
                        derivations={derivations.filter(
                            (option) => option.value === 'percent',
                        )}
                        currency={currency}
                        hasOwnPrices={prices.length > 0}
                        direct={form(false)}
                        whileFollowing={form(true)}
                    />
                )}
            </RatePlanShell>
        </>
    );
}

RatePlanPackagePricing.layout = (props: { ratePlan: RatePlan }) => ({
    breadcrumbs: ratePlanBreadcrumbs(props.ratePlan),
});
