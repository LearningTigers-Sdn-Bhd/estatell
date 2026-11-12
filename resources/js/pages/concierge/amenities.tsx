import { AmenityGroups } from '@/components/concierge/amenity-groups';
import { GuestFrame } from '@/components/concierge/stay-menu';

/** What the property offers, grouped the way the owner grouped it, with the details the owner wrote. */
export default function ConciergeAmenities({
    back,
    amenities,
}: {
    back: { href: string; label: string };
    amenities: App.Data.Concierge.ConciergeAmenityData[];
}) {
    return (
        <GuestFrame back={back} title="Hotel facilities">
            <AmenityGroups amenities={amenities} />
        </GuestFrame>
    );
}
