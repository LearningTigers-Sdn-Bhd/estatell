import { Link } from '@inertiajs/react';
import { Diamond } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { formatMoney } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { buttonVariants } from '@/components/ui/button';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { show } from '@/routes/reservations';
import { folio as folioRoute } from '@/routes/front-desk/stays';
import { groupMates, nightCount, placesOf } from './stay-places';
import { useStayFolio } from './use-stay-folio';

type Stay = App.Data.StayView.StayData;

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-2">
            <h3 className="text-sm font-medium">{title}</h3>
            {children}
        </section>
    );
}

function FolioLines({
    url,
    currency,
}: {
    url: string;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const { state, reload } = useStayFolio(url);

    if (state.status === 'failed') {
        return (
            <div className="flex flex-col items-start gap-2 text-sm">
                <p className="text-muted-foreground">
                    The folio could not be loaded.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={reload}
                >
                    Try again
                </Button>
            </div>
        );
    }

    if (state.status !== 'ready') {
        return (
            <div className="flex flex-col gap-2" aria-busy="true">
                <Skeleton className="h-5 w-full" />
                <Skeleton className="h-5 w-full" />
                <Skeleton className="h-5 w-2/3" />
            </div>
        );
    }

    const { folio } = state;

    return (
        <div className="flex flex-col gap-2 text-sm">
            <p className="flex justify-between font-medium">
                <span>Balance</span>
                <span className="tabular-nums">
                    {formatMoney(folio.balance, currency)}
                </span>
            </p>
            {folio.lines.length === 0 ? (
                <p className="text-muted-foreground">Nothing is posted yet.</p>
            ) : (
                <ul className="flex flex-col divide-y">
                    {folio.lines.map((line) => (
                        <li
                            key={line.id}
                            className="flex items-baseline justify-between gap-3 py-1.5"
                        >
                            <span className="min-w-0 flex-1 truncate">
                                {line.description}
                                <span className="text-xs text-muted-foreground">
                                    {' '}
                                    · <DateText date={line.date} />
                                </span>
                            </span>
                            <span className="tabular-nums">
                                {formatMoney(line.amount, currency)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {folio.hasMore && (
                <p className="text-xs text-muted-foreground">
                    Showing the latest lines. The reservation page has the whole
                    folio.
                </p>
            )}
        </div>
    );
}

/**
 * A Sheet on the right with a stay at a glance: who, when, where, the balance flag, the other units of its group
 * booking and the newest folio lines. The summary comes from the loaded stays; only the folio lines are asked for.
 * Check in, check out, move and payments are done on the reservation page.
 */
export function StayPeekSheet({
    stay,
    stays,
    rooms,
    currency,
    canViewReservations,
    onSelect,
    onClose,
}: {
    stay: Stay | null;
    stays: Stay[];
    rooms: App.Data.StayView.RoomRowData[];
    currency: App.Data.Property.CurrencyData | null;
    canViewReservations: boolean;
    onSelect: (unitId: number) => void;
    onClose: () => void;
}) {
    const mates = stay ? groupMates(stays, stay) : [];
    const places = stay ? placesOf(stay, rooms) : [];
    const nights = stay ? nightCount(stay.arrivalDate, stay.departureDate) : 0;

    return (
        <Sheet open={stay !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="data-[side=right]:sm:max-w-md">
                {stay && (
                    <>
                        <SheetHeader>
                            <SheetTitle>
                                {stay.guestLabel ?? 'No guest named'}
                            </SheetTitle>
                            <SheetDescription>
                                Reservation {stay.reference}
                            </SheetDescription>
                        </SheetHeader>
                        <div className="flex flex-1 flex-col gap-5 overflow-y-auto px-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge status={stay.status} />
                                {stay.balanceDue && (
                                    <span className="rounded-md bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning">
                                        Balance due
                                    </span>
                                )}
                                {stay.groupName && (
                                    <span className="flex items-center gap-1 text-sm text-muted-foreground">
                                        <Diamond
                                            aria-hidden
                                            className="size-3 fill-current"
                                        />
                                        {stay.groupName}
                                    </span>
                                )}
                            </div>
                            <Section title="Stay">
                                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                    <dt className="text-muted-foreground">
                                        Arrives
                                    </dt>
                                    <dd>
                                        <DateText date={stay.arrivalDate} />{' '}
                                        <span className="text-muted-foreground">
                                            {stay.arrivalTime}
                                        </span>
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Departs
                                    </dt>
                                    <dd>
                                        <DateText date={stay.departureDate} />{' '}
                                        <span className="text-muted-foreground">
                                            {stay.departureTime}
                                        </span>
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Nights
                                    </dt>
                                    <dd className="tabular-nums">{nights}</dd>
                                    <dt className="text-muted-foreground">
                                        Guests
                                    </dt>
                                    <dd>
                                        {stay.adults} adult
                                        {stay.adults === 1 ? '' : 's'}
                                        {stay.children > 0 &&
                                            `, ${stay.children} child${stay.children === 1 ? '' : 'ren'}`}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        {places.length > 1 ? 'Rooms' : 'Room'}
                                    </dt>
                                    <dd>
                                        <ul>
                                            {places.length === 0 && (
                                                <li className="text-muted-foreground">
                                                    Not assigned yet
                                                </li>
                                            )}
                                            {places.map((place) => (
                                                <li key={place.firstNight}>
                                                    {place.label}
                                                    {places.length > 1 && (
                                                        <span className="text-muted-foreground">
                                                            {' '}
                                                            ·{' '}
                                                            <DateText
                                                                date={
                                                                    place.firstNight
                                                                }
                                                            />
                                                        </span>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    </dd>
                                </dl>
                            </Section>
                            {mates.length > 1 && (
                                <Section title="This group">
                                    <ul className="flex flex-col divide-y text-sm">
                                        {mates.map((mate) => (
                                            <li key={mate.unitId}>
                                                <button
                                                    type="button"
                                                    aria-current={
                                                        mate.unitId ===
                                                        stay.unitId
                                                            ? 'true'
                                                            : undefined
                                                    }
                                                    className="flex w-full items-center justify-between gap-3 rounded-sm py-1.5 text-left outline-none focus-visible:ring-3 focus-visible:ring-ring/50 aria-[current=true]:font-medium"
                                                    onClick={() =>
                                                        onSelect(mate.unitId)
                                                    }
                                                >
                                                    <span className="min-w-0 flex-1 truncate">
                                                        {placesOf(
                                                            mate,
                                                            rooms,
                                                        )[0]?.label ??
                                                            'Not assigned yet'}
                                                        <span className="text-muted-foreground">
                                                            {' '}
                                                            ·{' '}
                                                            {mate.guestLabel ??
                                                                'No guest named'}
                                                        </span>
                                                    </span>
                                                    <StatusBadge
                                                        status={mate.status}
                                                    />
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                    <p className="text-xs text-muted-foreground">
                                        Only units in the dates on screen are
                                        listed.
                                    </p>
                                </Section>
                            )}
                            {canViewReservations && (
                                <Section title="Folio">
                                    <FolioLines
                                        url={folioRoute.url({
                                            reservation: stay.reservationId,
                                        })}
                                        currency={currency}
                                    />
                                </Section>
                            )}
                        </div>
                        {canViewReservations && (
                            <SheetFooter>
                                <Link
                                    href={show.url({
                                        reservation: stay.reservationId,
                                    })}
                                    className={buttonVariants()}
                                >
                                    {stay.groupName
                                        ? 'Open group booking'
                                        : 'Open reservation'}
                                </Link>
                            </SheetFooter>
                        )}
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
