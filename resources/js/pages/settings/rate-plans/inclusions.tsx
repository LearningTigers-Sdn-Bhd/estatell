import { Head } from '@inertiajs/react';
import { InclusionsForm } from '@/components/pms/rate-plans/inclusions-form';
import {
    RatePlanShell,
    ratePlanBreadcrumbs,
} from '@/components/pms/rate-plans/rate-plan-shell';

type RatePlan = App.Data.Rates.RatePlanData;
type Option = { value: string; label: string };

export default function RatePlanInclusions({
    ratePlan,
    inclusions,
    chargeItems,
    mealPeriods,
    chargingUnits,
    startOptions,
    endOptions,
    currency,
}: {
    ratePlan: RatePlan;
    inclusions: App.Data.Rates.RatePlanInclusionData[];
    chargeItems: App.Data.Rates.RatePlanInclusionChargeItemData[];
    mealPeriods: App.Data.Rates.RatePlanInclusionMealPeriodData[];
    chargingUnits: Option[];
    startOptions: Option[];
    endOptions: Option[];
    currency: App.Data.Property.CurrencyData;
}) {
    return (
        <>
            <Head title={`${ratePlan.name} inclusions`} />
            <RatePlanShell
                ratePlan={ratePlan}
                activeTab="inclusions"
                panelWidth="full"
            >
                <InclusionsForm
                    ratePlan={ratePlan}
                    inclusions={inclusions}
                    chargeItems={chargeItems}
                    mealPeriods={mealPeriods}
                    chargingUnits={chargingUnits}
                    startOptions={startOptions}
                    endOptions={endOptions}
                    currency={currency}
                />
            </RatePlanShell>
        </>
    );
}

RatePlanInclusions.layout = (props: { ratePlan: RatePlan }) => ({
    breadcrumbs: ratePlanBreadcrumbs(props.ratePlan),
});
