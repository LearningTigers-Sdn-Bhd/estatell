import { Head } from '@inertiajs/react';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    FacilityDetailsForm,
    type SharingOption,
} from '@/components/pms/settings/facility-details-form';
import {
    FacilityShell,
    facilityBreadcrumbs,
} from '@/components/pms/settings/facility-shell';

type Facility = App.Data.Facilities.FacilityData;

export default function FacilityShow({
    facility,
    sharingOptions,
    capacityUnits,
    rateBases,
    taxes,
    currency,
}: {
    facility: Facility;
    sharingOptions: SharingOption[];
    capacityUnits: EnumOption[];
    rateBases: EnumOption[];
    taxes: App.Data.Catalogues.ChargeItemTaxData[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <>
            <Head title={facility.name} />
            <FacilityShell
                facility={facility}
                activeTab="details"
                currency={currency}
            >
                <FacilityDetailsForm
                    key={facility.id}
                    facility={facility}
                    sharingOptions={sharingOptions}
                    capacityUnits={capacityUnits}
                    rateBases={rateBases}
                    taxes={taxes}
                    currency={currency}
                />
            </FacilityShell>
        </>
    );
}

FacilityShow.layout = (props: { facility: Facility }) => ({
    breadcrumbs: facilityBreadcrumbs(props, 'details'),
});
