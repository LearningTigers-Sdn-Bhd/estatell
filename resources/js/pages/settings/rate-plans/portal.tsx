import { Head } from '@inertiajs/react';
import { RatePlanPortalForm } from '@/components/pms/rate-plans/portal-form';
import {
    RatePlanShell,
    ratePlanBreadcrumbs,
} from '@/components/pms/rate-plans/rate-plan-shell';

type RatePlan = App.Data.Rates.RatePlanData;

export default function RatePlanPortal({
    ratePlan,
    portal,
    accessOptions,
    canViewCompanies,
}: {
    ratePlan: RatePlan;
    portal: App.Data.Rates.RatePlanPortalData;
    accessOptions: { value: string; label: string }[];
    canViewCompanies: boolean;
}) {
    return (
        <>
            <Head title={`${ratePlan.name} portal`} />
            <RatePlanShell ratePlan={ratePlan} activeTab="portal">
                <RatePlanPortalForm
                    ratePlan={ratePlan}
                    portal={portal}
                    accessOptions={accessOptions}
                    canViewCompanies={canViewCompanies}
                />
            </RatePlanShell>
        </>
    );
}

RatePlanPortal.layout = (props: { ratePlan: RatePlan }) => ({
    breadcrumbs: ratePlanBreadcrumbs(props.ratePlan),
});
