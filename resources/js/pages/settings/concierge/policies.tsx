import { Head } from '@inertiajs/react';
import {
    ConciergeShell,
    conciergeBreadcrumbs,
} from '@/components/pms/concierge/concierge-shell';
import {
    ChargePolicies,
    HouseRulesForm,
} from '@/components/pms/concierge/policies-tab';

export default function ConciergePolicies({
    houseRules,
    walkInEnabled,
    refundRequestsEnabled,
    policyTexts,
    policyTerms,
}: {
    houseRules: string | null;
    walkInEnabled: boolean;
    refundRequestsEnabled: boolean;
    policyTexts: {
        roomPolicy: string | null;
        paymentPolicy: string | null;
        otherPolicies: string | null;
    };
    policyTerms: App.Data.Concierge.ConciergePolicyTermData[];
}) {
    return (
        <>
            <Head title="Concierge policies" />
            <ConciergeShell
                activeTab="policies"
                asideLabel="Charge policies"
                aside={<ChargePolicies policyTerms={policyTerms} />}
            >
                <HouseRulesForm
                    houseRules={houseRules}
                    policyTexts={policyTexts}
                    walkInEnabled={walkInEnabled}
                    refundRequestsEnabled={refundRequestsEnabled}
                />
            </ConciergeShell>
        </>
    );
}

ConciergePolicies.layout = () => ({
    breadcrumbs: conciergeBreadcrumbs('policies'),
});
