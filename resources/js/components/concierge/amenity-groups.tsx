/** The facilities a property offers, grouped by category. A facility with details becomes a card; one without stays a tag. */
export function AmenityGroups({
    amenities,
}: {
    amenities: App.Data.Concierge.ConciergeAmenityData[];
}) {
    const groups = Object.entries(
        amenities.reduce<
            Record<string, App.Data.Concierge.ConciergeAmenityData[]>
        >((all, amenity) => {
            (all[amenity.category] ??= []).push(amenity);

            return all;
        }, {}),
    );

    return (
        <>
            {groups.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    The property has not listed its facilities yet. Ask the
                    front desk.
                </p>
            )}
            {groups.map(([category, items]) => (
                <section key={category} className="flex flex-col gap-2">
                    <h2 className="text-lg font-semibold">{category}</h2>
                    <ul className="flex flex-col gap-2">
                        {items.map((amenity) => (
                            <li key={amenity.name}>
                                <Facility amenity={amenity} />
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </>
    );
}

function Facility({
    amenity,
}: {
    amenity: App.Data.Concierge.ConciergeAmenityData;
}) {
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

    if (facts.length === 0 && !amenity.guestNotes) {
        return (
            <span className="inline-block rounded-full border px-3 py-1 text-sm">
                {amenity.name}
            </span>
        );
    }

    return (
        <div className="flex flex-col gap-2 rounded-lg border p-4">
            <h3 className="font-medium">{amenity.name}</h3>
            {facts.length > 0 && (
                <dl className="flex flex-col gap-1 text-sm">
                    {facts.map(([label, value]) => (
                        <div key={label} className="flex gap-3">
                            <dt className="w-24 shrink-0 text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-pretty">{value}</dd>
                        </div>
                    ))}
                </dl>
            )}
            {amenity.guestNotes && (
                <p className="text-sm text-pretty whitespace-pre-wrap text-muted-foreground">
                    {amenity.guestNotes}
                </p>
            )}
        </div>
    );
}
