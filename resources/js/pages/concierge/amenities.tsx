import { GuestFrame } from '@/components/concierge/stay-menu';

/** What the property offers, grouped the way the owner grouped it. */
export default function ConciergeAmenities({
    back,
    amenities,
}: {
    back: { href: string; label: string };
    amenities: App.Data.Concierge.ConciergeAmenityData[];
}) {
    const groups = Object.entries(
        amenities.reduce<Record<string, string[]>>((all, amenity) => {
            (all[amenity.category] ??= []).push(amenity.name);

            return all;
        }, {}),
    );

    return (
        <GuestFrame back={back} title="Hotel facilities">
            {groups.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    The property has not listed its facilities yet. Ask the
                    front desk.
                </p>
            )}
            {groups.map(([category, names]) => (
                <section key={category} className="flex flex-col gap-2">
                    <h2 className="text-lg font-semibold">{category}</h2>
                    <ul className="flex flex-wrap gap-2">
                        {names.map((name) => (
                            <li
                                key={name}
                                className="rounded-full border px-3 py-1 text-sm"
                            >
                                {name}
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </GuestFrame>
    );
}
