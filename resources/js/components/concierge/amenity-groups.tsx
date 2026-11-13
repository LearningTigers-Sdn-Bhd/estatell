import { Check, Sparkles } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
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
                        <div className="flex items-center gap-3 border-b border-border/40 pb-4">
                            <span className="flex size-10 items-center justify-center rounded-xl bg-muted/60 text-primary">
                                <Sparkles className="size-5" aria-hidden />
                            </span>
                            <h2 className="text-xl font-semibold">
                                {category}
                            </h2>
                            <span className="ml-auto text-sm text-muted-foreground tabular-nums">
                                {items.length}{' '}
                                {items.length === 1 ? 'facility' : 'facilities'}
                            </span>
                        </div>
                        {simple.length > 0 && (
                            <ul className="grid gap-x-6 sm:grid-cols-2 lg:grid-cols-3">
                                {simple.map((amenity) => (
                                    <li
                                        key={amenity.name}
                                        className="flex items-center gap-3 border-b border-border/30 py-4 text-sm"
                                    >
                                        <Check
                                            className="size-4 shrink-0 text-primary"
                                            aria-hidden
                                        />
                                        {amenity.name}
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
        <Card className="rounded-2xl">
            <CardHeader>
                <CardTitle>
                    <h3 className="text-lg font-semibold">{amenity.name}</h3>
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
