import { ArrowUpRight, MapPin, Navigation } from 'lucide-react';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { EmptyState } from '@/components/pms/empty-state';
import { Badge } from '@/components/ui/badge';

/** The property's recommendations, with direct links to directions. */
export default function ConciergeNearby({
    back,
    places,
}: {
    back: { href: string; label: string };
    places: App.Data.Concierge.ConciergePlaceData[];
}) {
    return (
        <GuestFrame
            back={back}
            title="Nearby places"
            description="Places recommended by your property. Open a location in maps for directions."
        >
            {places.length === 0 ? (
                <div className="rounded-2xl border border-border/40 bg-card px-5 py-8">
                    <EmptyState
                        icon={MapPin}
                        title="No nearby places listed yet"
                        description="Ask the front desk for places to visit, eat or explore nearby."
                    />
                </div>
            ) : (
                <section
                    className="flex flex-col gap-6"
                    aria-labelledby="nearby-places-title"
                >
                    <div className="flex items-center justify-between gap-3">
                        <h2
                            id="nearby-places-title"
                            className="text-lg font-semibold"
                        >
                            Explore nearby
                        </h2>
                        <span className="text-sm text-muted-foreground tabular-nums">
                            {places.length}{' '}
                            {places.length === 1 ? 'place' : 'places'}
                        </span>
                    </div>
                    <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {places.map((place) => (
                            <li key={place.mapsUrl}>
                                <a
                                    href={place.mapsUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="group flex h-full flex-col gap-5 rounded-2xl border border-border/40 bg-card p-6 transition-colors hover:bg-muted/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="flex size-12 items-center justify-center rounded-full bg-muted/60 text-primary">
                                            <MapPin
                                                className="size-6"
                                                strokeWidth={1.5}
                                                aria-hidden
                                            />
                                        </span>
                                        {place.distance && (
                                            <Badge variant="secondary">
                                                {place.distance} away
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="flex flex-1 flex-col gap-2">
                                        <h3 className="text-lg font-semibold text-pretty">
                                            {place.name}
                                        </h3>
                                        {place.description && (
                                            <p className="text-sm leading-relaxed text-muted-foreground">
                                                {place.description}
                                            </p>
                                        )}
                                    </div>
                                    <span className="flex items-center justify-between gap-3 border-t border-border/40 pt-4 text-sm font-medium text-primary">
                                        <span className="flex items-center gap-2">
                                            <Navigation
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Get directions
                                        </span>
                                        <ArrowUpRight
                                            className="size-4"
                                            aria-hidden
                                        />
                                        <span className="sr-only">
                                            Opens in maps in a new tab
                                        </span>
                                    </span>
                                </a>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </GuestFrame>
    );
}
