import { Head } from '@inertiajs/react';
import { AmenitiesTab } from '@/components/pms/concierge/amenities-tab';
import {
    ConciergeShell,
    conciergeBreadcrumbs,
} from '@/components/pms/concierge/concierge-shell';

export default function ConciergeAmenities({
    amenities,
    amenityDetails,
    page,
    listUrl,
}: {
    amenities: App.Data.BookingEngine.AmenityPickerData;
    amenityDetails: App.Data.Concierge.ConciergeAmenitySettingsData[];
    page: number;
    listUrl: string;
}) {
    return (
        <>
            <Head title="Concierge amenities" />
            <ConciergeShell activeTab="amenities">
                <AmenitiesTab
                    picker={amenities}
                    page={page}
                    listUrl={listUrl}
                    details={amenityDetails}
                />
            </ConciergeShell>
        </>
    );
}

ConciergeAmenities.layout = () => ({
    variant: 'fixed',
    breadcrumbs: conciergeBreadcrumbs('amenities'),
});
