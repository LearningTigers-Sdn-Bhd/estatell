import { Head } from '@inertiajs/react';
import {
    ConciergeShell,
    conciergeBreadcrumbs,
} from '@/components/pms/concierge/concierge-shell';
import { NearbyTab } from '@/components/pms/concierge/nearby-tab';

export default function ConciergeNearbyPlaces({
    nearbyPlaces,
    suggestions,
}: {
    nearbyPlaces: App.Data.BookingEngine.PropertyNearbyPlaceData[];
    suggestions: App.Data.BookingEngine.NearbyPlaceSuggestionData[];
}) {
    return (
        <>
            <Head title="Concierge nearby places" />
            <ConciergeShell activeTab="nearby-places">
                <NearbyTab places={nearbyPlaces} suggestions={suggestions} />
            </ConciergeShell>
        </>
    );
}

ConciergeNearbyPlaces.layout = () => ({
    variant: 'fixed',
    breadcrumbs: conciergeBreadcrumbs('nearby-places'),
});
