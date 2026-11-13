import {
    ArrowUpRight,
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
        <GuestFrame
            back={back}
            title="Contact us"
            description="Call the front desk, send a message, or find your way to the property."
        >
            <div className="grid items-start gap-8 lg:grid-cols-5 lg:gap-12">
                <aside className="flex flex-col gap-6 lg:col-span-2">
                    <div className="flex flex-col gap-5 rounded-2xl bg-muted/50 p-6 sm:p-8">
                        <div className="flex items-center gap-3">
                            <span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-background text-primary">
                                <Phone className="size-5" aria-hidden />
                            </span>
                            <h2 className="text-xl font-semibold">
                                Front desk
                            </h2>
                        </div>
                        {contacts.deskOpen !== null && (
                            <div className="flex flex-col gap-3">
                                <p className="flex items-center gap-2 text-sm font-medium">
                                    <Clock
                                        className="size-4 text-primary"
                                        aria-hidden
                                    />
                                    {contacts.deskOpen
                                        ? 'Open now'
                                        : 'Currently closed'}
                                </p>
                                {contacts.deskHours && (
                                    <p className="text-sm leading-relaxed text-muted-foreground">
                                        Opening hours: {contacts.deskHours}
                                    </p>
                                )}
                                {contacts.afterHoursMessage && (
                                    <p className="text-sm leading-relaxed whitespace-pre-wrap text-muted-foreground">
                                        {contacts.afterHoursMessage}
                                    </p>
                                )}
                            </div>
                        )}
                        <p className="text-sm leading-relaxed text-muted-foreground">
                            For help with your room, booking or stay, use the
                            contact details on this page.
                        </p>
                    </div>
                    <img
                        src="/images/concierge-host.png"
                        alt=""
                        width={1024}
                        height={1024}
                        className="hidden size-44 self-center object-contain lg:block"
                    />
                </aside>
                <section
                    className="flex flex-col gap-5 lg:col-span-3"
                    aria-labelledby="contact-details-title"
                >
                    <h2
                        id="contact-details-title"
                        className="text-lg font-semibold"
                    >
                        Get in touch
                    </h2>
                    {rows.length === 0 && (
                        <p className="rounded-2xl border border-border/40 p-6 text-sm text-muted-foreground">
                            The property has not listed contact details yet.
                            Please visit the front desk for help.
                        </p>
                    )}
                    <ul className="grid gap-4 sm:grid-cols-2">
                        {rows.map(({ icon: Icon, label, value, href }) => {
                            const body = (
                                <>
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted/50 text-primary">
                                        <Icon className="size-5" aria-hidden />
                                    </span>
                                    <span className="flex min-w-0 flex-1 flex-col gap-2">
                                        <span className="text-xs font-medium text-muted-foreground">
                                            {label}
                                        </span>
                                        <span className="text-sm font-medium break-words">
                                            {value}
                                        </span>
                                        {href && (
                                            <span className="flex items-center gap-1 text-xs text-primary">
                                                {href.startsWith('tel:')
                                                    ? 'Tap to call'
                                                    : href.startsWith('mailto:')
                                                      ? 'Send email'
                                                      : 'Open link'}
                                                <ArrowUpRight
                                                    className="size-3"
                                                    aria-hidden
                                                />
                                            </span>
                                        )}
                                    </span>
                                </>
                            );
                            const className =
                                'flex h-full items-start gap-3 rounded-2xl border border-border/40 bg-card p-5 transition-colors';
                            return (
                                <li
                                    key={label}
                                    className={
                                        label === 'Address'
                                            ? 'sm:col-span-2'
                                            : ''
                                    }
                                >
                                    {href ? (
                                        <a
                                            href={href}
                                            target={
                                                href.startsWith('http')
                                                    ? '_blank'
                                                    : undefined
                                            }
                                            rel="noopener noreferrer"
                                            className={`${className} hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary`}
                                        >
                                            {body}
                                            {href.startsWith('http') && (
                                                <span className="sr-only">
                                                    Opens in a new tab
                                                </span>
                                            )}
                                        </a>
                                    ) : (
                                        <div className={className}>{body}</div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </section>
            </div>
            {(emergency.length > 0 || contacts.emergencyInstructions) && (
                <section
                    className="flex flex-col gap-5 border-t border-border/40 pt-8"
                    aria-labelledby="emergency-title"
                >
                    <div className="flex items-center gap-3">
                        <ShieldAlert
                            className="size-5 text-destructive"
                            aria-hidden
                        />
                        <h2
                            id="emergency-title"
                            className="text-lg font-semibold"
                        >
                            Emergency contacts
                        </h2>
                    </div>
                    {contacts.emergencyInstructions && (
                        <p className="max-w-2xl text-sm leading-relaxed whitespace-pre-wrap">
                            {contacts.emergencyInstructions}
                        </p>
                    )}
                    <ul className="grid gap-4 sm:grid-cols-2">
                        {emergency.map(({ icon: Icon, label, value, href }) => (
                            <li key={label}>
                                <a
                                    href={href ?? undefined}
                                    className="flex min-h-24 items-center gap-4 rounded-2xl border border-destructive/20 bg-card p-5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                >
                                    <Icon
                                        className="size-5 shrink-0 text-destructive"
                                        aria-hidden
                                    />
                                    <span className="flex min-w-0 flex-1 flex-col gap-1">
                                        <span className="text-sm text-muted-foreground">
                                            {label}
                                        </span>
                                        <span className="text-lg font-semibold break-words tabular-nums">
                                            {value}
                                        </span>
                                    </span>
                                    <ArrowUpRight
                                        className="size-4 shrink-0"
                                        aria-hidden
                                    />
                                </a>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </GuestFrame>
    );
}
