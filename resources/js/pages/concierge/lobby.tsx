import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    ArrowRight,
    ArrowUpRight,
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
    heroUrl,
    walkInEnabled,
    bookingUrl,
}: {
    property: App.Data.Property.PropertySummaryData;
    walkInEnabled: boolean;
    bookingUrl: string | null;
    heroUrl: string | null;
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
            body: 'Use your booking code to check in or open your stay.',
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

    const primaryTile = tiles[0];
    const focusStyles =
        'focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-foreground';

    return (
        <>
            <Head title={property.name} />
            <div className="concierge-editorial min-h-0 flex-1 overflow-y-auto bg-background text-foreground">
                <div className="concierge-editorial-layout">
                    <div className="flex flex-col gap-6 lg:flex-1">
                        <section
                            aria-labelledby="concierge-welcome"
                            className="concierge-editorial-hero grid gap-6 lg:flex-1 lg:grid-cols-2 lg:gap-0"
                        >
                            <div className="flex min-w-0 flex-col items-start justify-center gap-5 lg:py-6 lg:pr-6">
                                <div className="flex w-full items-center gap-4">
                                    {property.logoUrl && (
                                        <img
                                            src={property.logoUrl}
                                            alt=""
                                            width={32}
                                            height={32}
                                            className="size-8 shrink-0 object-contain"
                                        />
                                    )}
                                    <p className="shrink-0 text-xs font-semibold tracking-widest uppercase">
                                        A guide to your stay
                                    </p>
                                    <span
                                        className="h-px flex-1 bg-border"
                                        aria-hidden
                                    />
                                </div>
                                <h1
                                    id="concierge-welcome"
                                    className="concierge-editorial-title"
                                >
                                    {property.name}
                                </h1>
                                <p className="concierge-editorial-serif text-xl leading-snug italic sm:text-2xl">
                                    Make yourself at home.
                                </p>
                                <p className="concierge-editorial-serif hidden max-w-md text-base leading-7 sm:text-lg lg:block">
                                    Check in with your booking code, find useful
                                    property information, and discover places
                                    nearby. Your stay starts here.
                                </p>
                            </div>
                            <div className="concierge-editorial-image relative overflow-hidden bg-muted">
                                <img
                                    src={
                                        heroUrl ??
                                        '/images/concierge-editorial-coast.jpg'
                                    }
                                    alt=""
                                    width={1672}
                                    height={941}
                                    fetchPriority="high"
                                    className="absolute inset-0 size-full object-cover object-center"
                                />
                            </div>
                            <div className="flex flex-col gap-2 lg:hidden">
                                <Link
                                    href={primaryTile.href}
                                    className={`concierge-editorial-serif flex min-h-14 w-full items-center justify-between gap-4 bg-primary px-5 py-3 text-2xl text-primary-foreground hover:opacity-90 motion-safe:transition-opacity ${focusStyles}`}
                                >
                                    {primaryTile.title}
                                    <ArrowRight
                                        className="size-5 shrink-0"
                                        aria-hidden
                                    />
                                </Link>
                                <p className="text-xs leading-5 text-muted-foreground">
                                    {primaryTile.body}
                                </p>
                            </div>
                        </section>

                        <div className="flex shrink-0 flex-col gap-4 border-t pt-4 lg:flex-row lg:items-center lg:gap-5">
                            <p
                                id="guest-services"
                                className="shrink-0 text-xs font-semibold tracking-widest uppercase"
                            >
                                Guest services
                            </p>
                            <nav
                                aria-labelledby="guest-services"
                                className="grid flex-1 grid-cols-1 gap-x-3 gap-y-2 sm:grid-cols-3 lg:flex lg:flex-wrap lg:gap-6"
                            >
                                {tiles.map(
                                    (
                                        {
                                            title,
                                            body,
                                            icon: Icon,
                                            href,
                                            external,
                                        },
                                        index,
                                    ) => {
                                        const content = (
                                            <>
                                                <span className="flex items-center gap-3">
                                                    {index > 0 && (
                                                        <Icon
                                                            className="size-4 shrink-0"
                                                            strokeWidth={1.5}
                                                            aria-hidden
                                                        />
                                                    )}
                                                    <span>{title}</span>
                                                </span>
                                                <span className="sr-only">
                                                    . {body}
                                                </span>
                                                {!external && index > 0 && (
                                                    <ArrowRight
                                                        className="size-4 shrink-0 motion-safe:transition-transform motion-safe:group-hover:translate-x-1"
                                                        aria-hidden
                                                    />
                                                )}
                                                {external && (
                                                    <>
                                                        <ArrowUpRight
                                                            className="size-4 shrink-0"
                                                            aria-hidden
                                                        />
                                                        <span className="sr-only">
                                                            Opens in a new tab
                                                        </span>
                                                    </>
                                                )}
                                            </>
                                        );
                                        const className =
                                            index === 0
                                                ? `concierge-editorial-serif hidden min-h-13 items-center justify-center gap-2 bg-primary px-5 py-3 text-xl text-primary-foreground hover:opacity-90 motion-safe:transition-opacity lg:flex lg:min-w-40 lg:flex-1 ${focusStyles}`
                                                : `concierge-editorial-serif group flex min-h-13 items-center justify-between gap-3 border border-border/60 px-4 py-3 text-lg hover:bg-muted/50 motion-safe:transition-colors lg:relative lg:min-w-40 lg:flex-1 lg:before:absolute lg:before:-left-3 lg:before:top-2 lg:before:bottom-2 lg:before:w-px lg:before:bg-border ${focusStyles}`;

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
                </div>
            </div>
        </>
    );
}
