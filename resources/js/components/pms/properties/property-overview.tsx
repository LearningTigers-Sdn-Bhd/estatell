import type { ReactNode } from 'react';

type Property = App.Data.Platform.AdminPropertyData;

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-1">
            <h2 className="text-sm font-medium text-muted-foreground">
                {title}
            </h2>
            <div className="text-sm">{children}</div>
        </section>
    );
}

/**
 * The Overview tab body, shared by the admin and partner property pages.
 */
export function PropertyOverview({ property }: { property: Property }) {
    const cityLine = [property.postalCode, property.city]
        .filter(Boolean)
        .join(' ');
    const address = [
        property.addressLine1,
        property.addressLine2,
        [cityLine, property.stateCode, property.countryCode]
            .filter(Boolean)
            .join(', '),
    ].filter(Boolean);
    const contact = [property.contactEmail, property.contactPhone].filter(
        Boolean,
    );
    const registration = [
        property.registrationNumber && `Reg. ${property.registrationNumber}`,
        property.tin && `TIN ${property.tin}`,
    ].filter(Boolean);

    return (
        <div className="flex flex-col gap-5">
            <Section title="Address">
                {address.length ? (
                    address.map((line) => <p key={line}>{line}</p>)
                ) : (
                    <p className="text-muted-foreground">Not set yet</p>
                )}
            </Section>
            <Section title="Contact">
                {contact.length ? (
                    <p>{contact.join(' · ')}</p>
                ) : (
                    <p className="text-muted-foreground">Not set yet</p>
                )}
            </Section>
            <Section title="Registration">
                {registration.length ? (
                    <p>{registration.join(' · ')}</p>
                ) : (
                    <p className="text-muted-foreground">Not set yet</p>
                )}
            </Section>
        </div>
    );
}
