import { Head } from '@inertiajs/react';
import {
    ConciergeShell,
    conciergeBreadcrumbs,
} from '@/components/pms/concierge/concierge-shell';
import {
    GettingHereForm,
    type ParkingOptions,
} from '@/components/pms/concierge/getting-here-form';
import { LinkedSection } from '@/components/pms/concierge/guide-layout';
import { profile } from '@/routes/settings';

/** Concierge › Getting here: directions, travel times, transfers and parking, with the property profile link in the rail. */
export default function ConciergeGettingHere({
    hasAddress,
    gettingHere,
    parkingOptions,
}: {
    hasAddress: boolean;
    gettingHere: App.Data.Concierge.ConciergeGettingHereData;
    parkingOptions: ParkingOptions;
}) {
    return (
        <>
            <Head title="Concierge getting here" />
            <ConciergeShell
                activeTab="getting-here"
                asideLabel="Property profile"
                aside={
                    <LinkedSection
                        title="Property profile"
                        body="Directions start from the address on the property profile."
                        ready={hasAddress}
                        href={profile.url()}
                        action="Edit property profile"
                    />
                }
            >
                <GettingHereForm
                    info={gettingHere}
                    parkingOptions={parkingOptions}
                />
            </ConciergeShell>
        </>
    );
}

ConciergeGettingHere.layout = () => ({
    variant: 'fixed',
    breadcrumbs: conciergeBreadcrumbs('getting-here'),
});
