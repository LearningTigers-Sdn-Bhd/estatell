import { Head } from '@inertiajs/react';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { PortalCompanyForm } from '@/components/pms/portal/portal-company-form';
import { company } from '@/routes/portal';

export default function PortalCompany({
    propertyCode,
    company: companyData,
    countries,
}: {
    propertyCode: string;
    company: App.Data.Portal.PortalCompanyData;
    countries: EnumOption[];
}) {
    return (
        <>
            <Head title="Company details" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Company details"
                    description="Your company's contact, tax numbers and billing address at this property."
                />

                <PortalCompanyForm
                    propertyCode={propertyCode}
                    company={companyData}
                    countries={countries}
                />
            </div>
        </>
    );
}

PortalCompany.layout = (props: { propertyCode: string }) => ({
    breadcrumbs: [
        {
            title: 'Company details',
            href: company({ property: props.propertyCode }),
        },
    ],
});
