import { Head } from '@inertiajs/react';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { PortalCompanyForm } from '@/components/pms/portal/portal-company-form';

export default function PortalCompany({
    propertyCode,
    company,
    countries,
}: {
    propertyCode: string;
    company: App.Data.Portal.PortalCompanyData;
    countries: EnumOption[];
}) {
    return (
        <>
            <Head title="Company" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Company"
                    description="Your company's contact, tax numbers and billing address at this property."
                />

                <PortalCompanyForm
                    propertyCode={propertyCode}
                    company={company}
                    countries={countries}
                />
            </div>
        </>
    );
}
