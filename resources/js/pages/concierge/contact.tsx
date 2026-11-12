import {
    Clock,
    Mail,
    MapPin,
    MessageCircle,
    Phone,
    ShieldAlert,
    Siren,
    TriangleAlert,
} from 'lucide-react';
import { GuestFrame } from '@/components/concierge/stay-menu';

/** How to reach the property: phone, email, address and a map link. */
export default function ConciergeContact({
    back,
    details,
    contacts,
}: {
    back: { href: string; label: string };
    details: App.Data.Concierge.ConciergePropertyData;
    contacts: App.Data.Concierge.ConciergeContactData;
}) {
    const rows = [
        contacts.dutyManagerPhone && {
            icon: TriangleAlert,
            label: 'Duty manager (urgent only)',
            value: contacts.dutyManagerPhone,
            href: `tel:${contacts.dutyManagerPhone}`,
        },
        contacts.deskPhone && {
            icon: Phone,
            label: 'Front desk',
            value: contacts.deskPhone,
            href: `tel:${contacts.deskPhone}`,
        },
        contacts.deskExtension && {
            icon: Phone,
            label: 'From a room phone, dial',
            value: contacts.deskExtension,
            href: null,
        },
        details.phone && {
            icon: Phone,
            label: 'Phone',
            value: details.phone,
            href: `tel:${details.phone}`,
        },
        contacts.whatsappUrl && {
            icon: MessageCircle,
            label: 'WhatsApp',
            value: contacts.whatsappNumber ?? 'Message us',
            href: contacts.whatsappUrl,
        },
        contacts.deskEmail && {
            icon: Mail,
            label: 'Front desk email',
            value: contacts.deskEmail,
            href: `mailto:${contacts.deskEmail}`,
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

    const emergency = [
        contacts.emergencyPhone && {
            icon: ShieldAlert,
            label: 'Property security',
            value: contacts.emergencyPhone,
            href: `tel:${contacts.emergencyPhone}`,
        },
        contacts.emergencyServicesNumber && {
            icon: Siren,
            label: 'Police, fire, ambulance',
            value: contacts.emergencyServicesNumber,
            href: `tel:${contacts.emergencyServicesNumber}`,
        },
    ].filter(Boolean) as typeof rows;

    return (
        <GuestFrame back={back} title="Contact us">
            {contacts.deskOpen !== null && (
                <div className="flex items-start gap-3 rounded-lg border p-4">
                    <Clock
                        className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                        aria-hidden
                    />
                    <span className="flex flex-col gap-0.5">
                        <span className="font-medium">
                            {contacts.deskOpen
                                ? 'The front desk is open'
                                : 'The front desk is closed'}
                        </span>
                        {contacts.deskHours && (
                            <span className="text-sm text-muted-foreground">
                                Open {contacts.deskHours}
                            </span>
                        )}
                        {contacts.afterHoursMessage && (
                            <span className="text-sm text-pretty text-muted-foreground">
                                {contacts.afterHoursMessage}
                            </span>
                        )}
                    </span>
                </div>
            )}
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
            {(emergency.length > 0 || contacts.emergencyInstructions) && (
                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold">Emergency</h2>
                    {contacts.emergencyInstructions && (
                        <p className="text-sm leading-relaxed whitespace-pre-wrap">
                            {contacts.emergencyInstructions}
                        </p>
                    )}
                    <ul className="flex flex-col gap-3">
                        {emergency.map(({ icon: Icon, label, value, href }) => (
                            <li key={label}>
                                <a
                                    href={href ?? undefined}
                                    className="flex items-start gap-3 rounded-lg border p-4 transition-colors hover:bg-muted"
                                >
                                    <Icon
                                        className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                        aria-hidden
                                    />
                                    <span className="flex flex-col gap-0.5">
                                        <span className="text-sm text-muted-foreground">
                                            {label}
                                        </span>
                                        <span className="font-medium">
                                            {value}
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
