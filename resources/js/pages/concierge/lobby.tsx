import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    BedDouble,
    BookOpen,
    CalendarDays,
    LogIn,
    MapPin,
    Undo2,
    Phone,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    contact,
    enterCode,
    info,
    nearbyPlaces,
    show,
    walkIn,
} from '@/routes/concierge';

/** A stay saved on this phone is offered for as long as its link can last: until 8 days after the day it was saved. */
const SAVED_STAY_DAYS = 8;

type Tile = {
    title: string;
    body: string;
    icon: LucideIcon;
    href: string;
    external?: boolean;
};

/** What a guest sees after scanning the lobby QR: the services of the property, with check-in first. */
export default function ConciergeLobby({
    property,
    walkInEnabled,
    bookingUrl,
}: {
    property: App.Data.Property.PropertySummaryData;
    walkInEnabled: boolean;
    bookingUrl: string | null;
}) {
    const code = { property: property.code };
    const [savedToken, setSavedToken] = useState<string | null>(null);

    useEffect(() => {
        try {
            const saved = JSON.parse(
                localStorage.getItem(`concierge-stay:${property.code}`) ??
                    'null',
            ) as { token?: string; at?: number } | null;

            if (
                saved?.token &&
                saved.at &&
                Date.now() - saved.at < SAVED_STAY_DAYS * 86_400_000
            ) {
                setSavedToken(saved.token);
            }
        } catch {
            // Storage can be blocked or hold something else; the hub works without it.
        }
    }, [property.code]);

    const tiles: Tile[] = [
        ...(savedToken
            ? [
                  {
                      title: 'Continue your stay',
                      body: 'Requests, your bill, documents and check-out.',
                      icon: Undo2,
                      href: show.url({ token: savedToken }),
                  },
              ]
            : []),
        {
            title: 'Check in',
            body: 'Enter your booking code. Already checked in? It opens your stay.',
            icon: LogIn,
            href: enterCode.url(code),
        },
        ...(walkInEnabled
            ? [
                  {
                      title: 'Book a room for tonight',
                      body: 'No booking? Choose a room and check in on this phone.',
                      icon: BedDouble,
                      href: walkIn.url(code),
                  },
              ]
            : []),
        ...(bookingUrl
            ? [
                  {
                      title: 'Book a room',
                      body: 'See rooms and prices for another date.',
                      icon: CalendarDays,
                      href: bookingUrl,
                      external: true,
                  },
              ]
            : []),
        {
            title: 'Property guide',
            body: 'About us, facilities, policies and FAQs.',
            icon: BookOpen,
            href: info.url(code),
        },
        {
            title: 'Nearby places',
            body: 'Places worth a visit close by.',
            icon: MapPin,
            href: nearbyPlaces.url(code),
        },
        {
            title: 'Contact us',
            body: 'Phone, email and the map.',
            icon: Phone,
            href: contact.url(code),
        },
    ];

    return (
        <>
            <Head title={property.name} />
            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-8">
                <div className="mx-auto flex w-full max-w-md flex-col gap-6">
                    <div className="flex flex-col items-center gap-3 text-center">
                        {property.logoUrl && (
                            <img
                                src={property.logoUrl}
                                alt=""
                                className="size-16 rounded-xl object-contain"
                            />
                        )}
                        <h1 className="font-heading text-2xl font-semibold">
                            {property.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            How can we help with your stay?
                        </p>
                    </div>

                    <nav aria-label="Services" className="flex flex-col gap-3">
                        {tiles.map(
                            ({ title, body, icon: Icon, href, external }) => {
                                const content = (
                                    <>
                                        <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                            <Icon
                                                className="size-5"
                                                aria-hidden
                                            />
                                        </span>
                                        <span className="flex flex-col gap-0.5">
                                            <span className="font-medium">
                                                {title}
                                            </span>
                                            <span className="text-sm text-pretty text-muted-foreground">
                                                {body}
                                            </span>
                                        </span>
                                    </>
                                );
                                const className =
                                    'flex items-start gap-3 rounded-lg border p-4 transition-colors hover:bg-muted';

                                return external ? (
                                    <a
                                        key={title}
                                        href={href}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className={className}
                                    >
                                        {content}
                                    </a>
                                ) : (
                                    <Link
                                        key={title}
                                        href={href}
                                        className={className}
                                    >
                                        {content}
                                    </Link>
                                );
                            },
                        )}
                    </nav>
                </div>
            </div>
        </>
    );
}
