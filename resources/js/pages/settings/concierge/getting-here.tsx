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
import { profile as profileRoute } from '@/routes/settings';

/** Concierge › Getting here: directions, travel times, transfers and parking, with the profile address read-only in the rail. */
export default function ConciergeGettingHere({
    profile,
    gettingHere,
    parkingOptions,
}: {
    profile: App.Data.Concierge.ConciergeProfileData;
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
                        body="Directions start from this address. Change it on the property profile."
                        ready={Boolean(profile.addressLine1)}
                        fields={[
                            {
                                label: 'Address line 1',
                                value: profile.addressLine1,
                            },
                            {
                                label: 'Address line 2',
                                value: profile.addressLine2,
                            },
                            { label: 'Postcode', value: profile.postalCode },
                            { label: 'City', value: profile.city },
                            {
                                label: 'Google Maps link',
                                value: profile.googleMapsUrl,
                            },
                        ]}
                        href={profileRoute.url()}
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
