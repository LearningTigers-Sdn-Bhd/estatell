import {
    NearbyPlacesSection,
    type NearbyPlaceRoutes,
} from '@/components/pms/booking-website/nearby-places-section';
import { Card, CardContent } from '@/components/ui/card';
import * as concierge from '@/routes/settings/concierge/nearby-places';
import { update as active } from '@/routes/settings/concierge/nearby-places/active';

/** The same changes as on the Booking website page, sent to the Concierge routes so no booking engine is needed. */
const routes: NearbyPlaceRoutes = { ...concierge, active };

/** The places guests see near the property: switch them on, add one from a Google Maps link, reorder and describe them. */
export function NearbyTab({
    places,
    suggestions,
}: {
    places: App.Data.BookingEngine.PropertyNearbyPlaceData[];
    suggestions: App.Data.BookingEngine.NearbyPlaceSuggestionData[];
}) {
    return (
        <Card className="print:hidden">
            <CardContent>
                <NearbyPlacesSection
                    places={places}
                    suggestions={suggestions}
                    routes={routes}
                />
            </CardContent>
        </Card>
    );
}
