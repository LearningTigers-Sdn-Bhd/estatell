import { Head } from '@inertiajs/react';
import {
    FacilityDetailsForm,
    type BookedByOption,
    type RateBasesByBookedBy,
} from '@/components/pms/settings/facility-details-form';
import {
    FacilityShell,
    facilityBreadcrumbs,
} from '@/components/pms/settings/facility-shell';

type Facility = App.Data.Facilities.FacilityData;

export default function FacilityShow({
    facility,
    bookedByOptions,
    rateBases,
    taxes,
    currency,
}: {
    facility: Facility;
    bookedByOptions: BookedByOption[];
    rateBases: RateBasesByBookedBy;
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
                    bookedByOptions={bookedByOptions}
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
