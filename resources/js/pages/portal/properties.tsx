import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/pms/page-header';
import { PortalPropertiesTable } from '@/components/pms/portal/portal-properties-table';
import { properties as propertiesPage } from '@/routes/portal';

export default function PortalProperties({
    properties,
}: {
    properties: App.Data.Portal.PortalPropertyData[];
}) {
    return (
        <>
            <Head title="Properties" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Properties"
                    description="The properties that gave you a company portal login."
                />

                <PortalPropertiesTable properties={properties} />
            </div>
        </>
    );
}

PortalProperties.layout = {
    breadcrumbs: [{ title: 'Properties', href: propertiesPage() }],
};
