import { Check, Sparkles } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type Amenity = App.Data.Concierge.ConciergeAmenityData;

function hasDetails(amenity: Amenity): boolean {
    return !!(
        amenity.location ||
        amenity.openingHours ||
        amenity.feeInformation ||
        amenity.reservationRequired ||
        amenity.guestNotes
    );
}

/** Simple facilities stay compact; facilities with guest instructions get readable detail cards. */
export function AmenityGroups({ amenities }: { amenities: Amenity[] }) {
    const groups = Object.entries(
        amenities.reduce<Record<string, Amenity[]>>((all, amenity) => {
            (all[amenity.category] ??= []).push(amenity);
            return all;
        }, {}),
    );

    if (groups.length === 0) {
        return (
            <EmptyState
                icon={Sparkles}
                title="No facilities listed yet"
                description="Ask the front desk about the facilities available during your stay."
            />
        );
    }

    return (
        <div className="flex flex-col gap-8">
            {groups.map(([category, items]) => {
                const simple = items.filter((amenity) => !hasDetails(amenity));
                const detailed = items.filter(hasDetails);

                return (
                    <section key={category} className="flex flex-col gap-4">
                        <h2 className="font-heading text-lg font-medium">
                            {category}
                        </h2>
                        {simple.length > 0 && (
                            <ul className="flex flex-wrap gap-3">
                                {simple.map((amenity) => (
                                    <li key={amenity.name}>
                                        <Badge
                                            variant="secondary"
                                            size="lg"
                                            className="concierge-facility-tag"
                                        >
                                            <Check
                                                data-icon="inline-start"
                                                aria-hidden
                                            />
                                            {amenity.name}
                                        </Badge>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {detailed.length > 0 && (
                            <div className="grid gap-4 lg:grid-cols-2">
                                {detailed.map((amenity) => (
                                    <Facility
                                        key={amenity.name}
                                        amenity={amenity}
                                    />
                                ))}
                            </div>
                        )}
                    </section>
                );
            })}
        </div>
    );
}

function Facility({ amenity }: { amenity: Amenity }) {
    const facts = [
        ['Where', amenity.location],
        ['Open', amenity.openingHours],
        ['Cost', amenity.feeInformation],
        [
            'Reservation',
            amenity.reservationRequired
                ? (amenity.reservationInstructions ?? 'Required')
                : null,
        ],
    ].filter(([, value]) => value) as [string, string][];

    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    <h3>{amenity.name}</h3>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <dl className="flex flex-col divide-y">
                    {facts.map(([label, value]) => (
                        <div
                            key={label}
                            className="grid gap-1 py-3 first:pt-0 last:pb-0 sm:grid-cols-3 sm:gap-4"
                        >
                            <dt className="text-sm text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-sm leading-6 whitespace-pre-wrap sm:col-span-2">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
                {amenity.guestNotes && (
                    <p className="text-sm leading-7 whitespace-pre-wrap">
                        {amenity.guestNotes}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
