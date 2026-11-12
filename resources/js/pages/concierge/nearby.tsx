import { MapPin } from 'lucide-react';
import { GuestFrame } from '@/components/concierge/stay-menu';

/** Places near the property, each with a link that opens it in maps. */
export default function ConciergeNearby({
    back,
    places,
}: {
    back: { href: string; label: string };
    places: App.Data.Concierge.ConciergePlaceData[];
}) {
    return (
        <GuestFrame back={back} title="Nearby places">
            {places.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    The property has not listed places nearby yet. Ask the front
                    desk.
                </p>
            )}
            <ul className="flex flex-col gap-3">
                {places.map((place) => (
                    <li key={place.mapsUrl}>
                        <a
                            href={place.mapsUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex items-start gap-3 rounded-lg border p-4 transition-colors hover:bg-muted"
                        >
                            <MapPin
                                className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="flex flex-col gap-0.5">
                                <span className="font-medium">
                                    {place.name}
                                </span>
                                {place.description && (
                                    <span className="text-sm text-pretty text-muted-foreground">
                                        {place.description}
                                    </span>
                                )}
                                <span className="text-xs text-muted-foreground">
                                    {place.distance
                                        ? `${place.distance} away · Open in maps`
                                        : 'Open in maps'}
                                </span>
                            </span>
                        </a>
                    </li>
                ))}
            </ul>
        </GuestFrame>
    );
}
