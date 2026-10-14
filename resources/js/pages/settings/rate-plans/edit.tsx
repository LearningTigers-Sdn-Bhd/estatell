import { Head } from '@inertiajs/react';
import { DetailsForm } from '@/components/pms/rate-plans/details-form';
import {
    RatePlanShell,
    ratePlanBreadcrumbs,
} from '@/components/pms/rate-plans/rate-plan-shell';

type RatePlan = App.Data.Rates.RatePlanData;

export default function RatePlanEdit({
    ratePlan,
    currency,
    nightsLockedReason,
}: {
    ratePlan: RatePlan;
    currency: App.Data.Property.CurrencyData;
    nightsLockedReason: string | null;
}) {
    return (
        <>
            <Head title={ratePlan.name} />
            <RatePlanShell ratePlan={ratePlan} activeTab="details">
                <DetailsForm
                    ratePlan={ratePlan}
                    currency={currency}
                    nightsLockedReason={nightsLockedReason}
                />
            </RatePlanShell>
        </>
    );
}

RatePlanEdit.layout = (props: { ratePlan: RatePlan }) => ({
    breadcrumbs: ratePlanBreadcrumbs(props.ratePlan),
});
