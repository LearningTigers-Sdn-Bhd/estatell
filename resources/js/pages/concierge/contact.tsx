import { Mail, MapPin, Phone } from 'lucide-react';
import { GuestFrame } from '@/components/concierge/stay-menu';

/** How to reach the property: phone, email, address and a map link. */
export default function ConciergeContact({
    back,
    details,
}: {
    back: { href: string; label: string };
    details: App.Data.Concierge.ConciergePropertyData;
}) {
    const rows = [
        details.phone && {
            icon: Phone,
            label: 'Phone',
            value: details.phone,
            href: `tel:${details.phone}`,
        },
        details.email && {
            icon: Mail,
            label: 'Email',
            value: details.email,
            href: `mailto:${details.email}`,
        },
        details.addressLines.length > 0 && {
            icon: MapPin,
            label: 'Address',
            value: details.addressLines.join(', '),
            href: details.mapsUrl,
        },
    ].filter(Boolean) as {
        icon: typeof Phone;
        label: string;
        value: string;
        href: string | null;
    }[];

    return (
        <GuestFrame back={back} title="Contact us">
            {rows.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    The property has not listed its contact details yet.
                </p>
            )}
            <ul className="flex flex-col gap-3">
                {rows.map(({ icon: Icon, label, value, href }) => {
                    const body = (
                        <>
                            <Icon
                                className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="flex flex-col gap-0.5">
                                <span className="text-sm text-muted-foreground">
                                    {label}
                                </span>
                                <span className="font-medium text-pretty">
                                    {value}
                                </span>
                            </span>
                        </>
                    );

                    return (
                        <li key={label}>
                            {href ? (
                                <a
                                    href={href}
                                    target={
                                        href.startsWith('http')
                                            ? '_blank'
                                            : undefined
                                    }
                                    rel="noopener noreferrer"
                                    className="flex items-start gap-3 rounded-lg border p-4 transition-colors hover:bg-muted"
                                >
                                    {body}
                                </a>
                            ) : (
                                <div className="flex items-start gap-3 rounded-lg border p-4">
                                    {body}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </GuestFrame>
    );
}
