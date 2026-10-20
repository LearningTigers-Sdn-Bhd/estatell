import { Head } from '@inertiajs/react';
import { RatePlanCompaniesForm } from '@/components/pms/rate-plans/companies-form';
import {
    RatePlanShell,
    ratePlanBreadcrumbs,
} from '@/components/pms/rate-plans/rate-plan-shell';

type RatePlan = App.Data.Rates.RatePlanData;

export default function RatePlanCompanies({
    ratePlan,
    access,
    accessOptions,
    marketOptions,
    candidates,
    canManageCompanies,
    canViewCompanies,
}: {
    ratePlan: RatePlan;
    access: App.Data.Rates.RatePlanCompaniesData;
    accessOptions: { value: string; label: string }[];
    marketOptions: { value: string; label: string }[];
    candidates: App.Data.Rates.RatePlanCompanyCandidateData[];
    canManageCompanies: boolean;
    canViewCompanies: boolean;
}) {
    return (
        <>
            <Head title={`${ratePlan.name} companies`} />
            <RatePlanShell ratePlan={ratePlan} activeTab="companies">
                <RatePlanCompaniesForm
                    key={`${ratePlan.id}-${access.companyAccess}`}
                    ratePlan={ratePlan}
                    access={access}
                    accessOptions={accessOptions}
                    marketOptions={marketOptions}
                    candidates={candidates}
                    canManageCompanies={canManageCompanies}
                    canViewCompanies={canViewCompanies}
                />
            </RatePlanShell>
        </>
    );
}

RatePlanCompanies.layout = (props: { ratePlan: RatePlan }) => ({
    breadcrumbs: ratePlanBreadcrumbs(props.ratePlan),
});
