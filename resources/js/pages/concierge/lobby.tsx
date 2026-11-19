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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { SAVED_STAY_DAYS, readStays } from '@/lib/concierge-stays';
import type { SavedStay } from '@/lib/concierge-stays';
import {
    contact,
    enterCode,
    info,
    nearbyPlaces,
    show,
    walkIn,
} from '@/routes/concierge';

type Tile = {
    title: string;
    body: string;
    icon: LucideIcon;
    href: string;
    external?: boolean;
    onSelect?: () => void;
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
    const [savedStays, setSavedStays] = useState<SavedStay[]>([]);

    useEffect(() => {
        setSavedStays(readStays(property.code, SAVED_STAY_DAYS));
    }, [property.code]);

    const [picking, setPicking] = useState(false);

    // One stay goes straight to it. Several (a family on one phone) open a list to choose the room.
    const tiles: Tile[] = [
        ...(savedStays.length > 0
            ? [
                  {
                      title: 'Continue your stay',
                      body: 'Requests, your bill, documents and check-out.',
                      icon: Undo2,
                      href: show.url({ token: savedStays[0].token }),
                      onSelect:
                          savedStays.length > 1
                              ? () => setPicking(true)
                              : undefined,
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
                      title: 'Book a room for today',
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
    // A hairline in the middle of the gap above every tile after the first row, so the rows read as one grid.
    const rowDivider =
        'lg:before:absolute lg:before:-top-3 lg:before:-inset-x-1.5 lg:before:h-px lg:before:bg-border';
    const focusStyles =
        'focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-foreground';

    return (
        <>
            <Head title={property.name} />
            <div className="concierge-editorial min-h-0 flex-1 overflow-y-auto bg-background text-foreground">
                <div className="concierge-editorial-layout h-full! min-h-0!">
                    <div className="flex min-h-0 flex-1 flex-col gap-4 lg:grid lg:min-h-0 lg:flex-1 lg:grid-cols-2 lg:grid-rows-[auto_minmax(0,1fr)] lg:gap-x-10 lg:gap-y-6">
                        <section
                            aria-labelledby="concierge-welcome"
                            className="concierge-editorial-hero flex min-h-0 flex-1 flex-col gap-4 lg:contents"
                        >
                            <div className="flex min-w-0 flex-col items-start justify-center gap-3 lg:col-start-1 lg:row-start-1 lg:gap-5">
                                <div className="flex w-full items-center gap-4">
                                    {property.logoUrl && (
                                        <img
                                            src={property.logoUrl}
                                            alt=""
                                            width={64}
                                            height={64}
                                            className="size-12 shrink-0 object-contain lg:size-16"
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
                            <div className="concierge-editorial-image concierge-editorial-image-right relative min-h-16! flex-1 overflow-hidden bg-muted lg:col-start-1 lg:row-start-2 lg:min-h-0!">
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
                                {primaryTile.onSelect ? (
                                    <button
                                        type="button"
                                        onClick={primaryTile.onSelect}
                                        className={`concierge-editorial-serif flex min-h-14 w-full items-center justify-between gap-4 bg-primary px-5 py-3 text-2xl text-primary-foreground hover:opacity-90 motion-safe:transition-opacity ${focusStyles}`}
                                    >
                                        {primaryTile.title}
                                        <ArrowRight
                                            className="size-6 shrink-0"
                                            aria-hidden
                                        />
                                    </button>
                                ) : (
                                    <Link
                                        href={primaryTile.href}
                                        className={`concierge-editorial-serif flex min-h-14 w-full items-center justify-between gap-4 bg-primary px-5 py-3 text-2xl text-primary-foreground hover:opacity-90 motion-safe:transition-opacity ${focusStyles}`}
                                    >
                                        {primaryTile.title}
                                        <ArrowRight
                                            className="size-6 shrink-0"
                                            aria-hidden
                                        />
                                    </Link>
                                )}
                                <p className="text-xs leading-5 text-muted-foreground">
                                    {primaryTile.body}
                                </p>
                            </div>
                        </section>

                        <div className="flex shrink-0 flex-col gap-3 border-t pt-3 lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:justify-center lg:gap-4 lg:border-t-0 lg:pt-0">
                            <p
                                id="guest-services"
                                className="shrink-0 text-xs font-semibold tracking-widest uppercase"
                            >
                                Guest services
                            </p>
                            <nav
                                aria-labelledby="guest-services"
                                className="grid flex-1 grid-cols-3 gap-2 sm:gap-3 lg:flex-none lg:gap-y-6"
                            >
                                {tiles.map(
                                    (
                                        {
                                            title,
                                            body,
                                            icon: Icon,
                                            href,
                                            external,
                                            onSelect,
                                        },
                                        index,
                                    ) => {
                                        const content = (
                                            <>
                                                <span className="flex flex-col items-start gap-2 lg:gap-6">
                                                    <Icon
                                                        className={`size-6 shrink-0 lg:size-8 ${index === 0 ? 'hidden lg:block' : ''}`}
                                                        strokeWidth={1.5}
                                                        aria-hidden
                                                    />
                                                    <span>{title}</span>
                                                </span>
                                                <span className="sr-only">
                                                    . {body}
                                                </span>
                                                {!external && (
                                                    <ArrowRight
                                                        className={`absolute top-2 right-2 size-4 shrink-0 motion-safe:transition-transform motion-safe:group-hover:translate-x-1 lg:top-4 lg:right-4 lg:size-5 ${index === 0 ? 'hidden lg:block' : ''}`}
                                                        aria-hidden
                                                    />
                                                )}
                                                {external && (
                                                    <>
                                                        <ArrowUpRight
                                                            className="absolute top-2 right-2 size-4 shrink-0 lg:top-4 lg:right-4 lg:size-5"
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
                                                ? `concierge-editorial-serif hidden min-h-13 items-center justify-center gap-2 bg-primary px-5 py-3 text-xl text-primary-foreground hover:opacity-90 motion-safe:transition-opacity lg:relative lg:flex lg:aspect-square lg:items-end lg:justify-start lg:p-5 lg:text-2xl ${focusStyles}`
                                                : `concierge-editorial-serif group relative flex aspect-square items-end justify-start border border-border/60 p-3 text-base leading-tight hover:bg-muted/50 motion-safe:transition-colors lg:p-5 lg:text-xl ${index >= 3 ? rowDivider : ''} ${focusStyles}`;

                                        return onSelect ? (
                                            <button
                                                key={title}
                                                type="button"
                                                onClick={onSelect}
                                                className={className}
                                            >
                                                {content}
                                            </button>
                                        ) : external ? (
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
            <Dialog open={picking} onOpenChange={setPicking}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Which stay?</DialogTitle>
                        <DialogDescription>
                            These stays were opened on this phone.
                        </DialogDescription>
                    </DialogHeader>
                    <ul className="flex flex-col gap-2">
                        {savedStays.map((saved) => (
                            <li key={saved.token}>
                                <Link
                                    href={show.url({ token: saved.token })}
                                    className={`flex min-h-12 items-center justify-between gap-3 border border-border/60 px-4 py-3 hover:bg-muted/50 ${focusStyles}`}
                                >
                                    {saved.label}
                                    <ArrowRight
                                        className="size-5 shrink-0"
                                        aria-hidden
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </DialogContent>
            </Dialog>
        </>
    );
}
