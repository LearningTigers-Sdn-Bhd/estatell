import { Head } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import { index } from '@/routes/partner/properties';

export default function PartnerPropertiesIndex() {
    return (
        <>
            <Head title="Properties" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader title="Properties" />

                <EmptyState
                    icon={Building2}
                    title="No properties yet"
                    description="Properties you bring to estatell will appear here."
                />
            </div>
        </>
    );
}

PartnerPropertiesIndex.layout = {
    breadcrumbs: [{ title: 'Properties', href: index() }],
};
