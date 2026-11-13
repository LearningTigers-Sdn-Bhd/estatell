import { Head } from '@inertiajs/react';
import {
    BookingWebsiteShell,
    bookingWebsiteBreadcrumbs,
} from '@/components/pms/booking-website/booking-website-shell';
import { NearbyPlacesSection } from '@/components/pms/booking-website/nearby-places-section';

export default function BookingWebsiteNearbyPlaces({
    nearbyPlaces,
    suggestions,
}: {
    nearbyPlaces: App.Data.BookingEngine.PropertyNearbyPlaceData[];
    suggestions: App.Data.BookingEngine.NearbyPlaceSuggestionData[];
}) {
    return (
        <>
            <Head title="Booking website nearby places" />
            <BookingWebsiteShell activeTab="nearby-places">
                <NearbyPlacesSection
                    places={nearbyPlaces}
                    suggestions={suggestions}
                />
            </BookingWebsiteShell>
        </>
    );
}

BookingWebsiteNearbyPlaces.layout = (
    props: Parameters<typeof bookingWebsiteBreadcrumbs>[0],
) => ({
    breadcrumbs: bookingWebsiteBreadcrumbs(props, 'nearby-places'),
});
