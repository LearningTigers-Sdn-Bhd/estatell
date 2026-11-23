import { Head } from '@inertiajs/react';
import { Plug } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import { Card, CardContent } from '@/components/ui/card';
import { profile } from '@/routes/settings';
import { index } from '@/routes/settings/pos-integrations';

/**
 * Shown, instead of a 404, when someone arrives (for example from the Loyverse App Marketplace) at a property that does
 * not have POS integration yet. A platform admin turns the feature on.
 */
export default function PosIntegrationsActivate() {
    return (
        <>
            <Head title="POS integrations" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="POS integrations"
                    description="Connect a point of sale so guests can charge meals to their room."
                />

                <Card>
                    <CardContent>
                        <EmptyState
                            icon={Plug}
                            title="Contact support to activate POS Integration"
                            description="POS Integration is not switched on for this property yet. Ask Estatell support to activate it, then connect Loyverse here."
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

PosIntegrationsActivate.layout = {
    breadcrumbs: [
        { title: 'Settings', href: profile() },
        { title: 'POS integrations', href: index() },
    ],
};
