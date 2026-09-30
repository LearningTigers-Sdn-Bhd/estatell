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
}: {
    ratePlan: RatePlan;
    currency: App.Enums.Currency;
}) {
    return (
        <>
            <Head title={ratePlan.name} />
            <RatePlanShell ratePlan={ratePlan} activeTab="details">
                <DetailsForm ratePlan={ratePlan} currency={currency} />
            </RatePlanShell>
        </>
    );
}

RatePlanEdit.layout = (props: { ratePlan: RatePlan }) => ({
    breadcrumbs: ratePlanBreadcrumbs(props.ratePlan),
});
