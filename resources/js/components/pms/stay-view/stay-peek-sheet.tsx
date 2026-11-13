import { Link } from '@inertiajs/react';
import { Diamond } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { RoomTotalLabel } from '@/components/pms/room-total-label';
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
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { show } from '@/routes/reservations';
import { folio as folioRoute } from '@/routes/front-desk/stays';
import { GroupUnitCards } from './group-unit-cards';
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

/**
 * One label and amount row of the money box.
 */
function MoneyRow({
    label,
    amount,
    currency,
    className,
}: {
    label: React.ReactNode;
    amount: string;
    currency: App.Data.Property.CurrencyData | null;
    className?: string;
}) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className="flex items-center gap-2">{label}</dt>
            <dd className={className}>
                <MoneyText amount={amount} currency={currency} plain />
            </dd>
        </div>
    );
}

/**
 * The money box at the bottom of the sheet: room total, paid and balance, the same figures as the reservation card.
 * They load when the sheet opens.
 */
function FolioMoney({
    url,
    currency,
}: {
    url: string;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const { state, reload } = useStayFolio(url);

    if (state.status === 'failed') {
        return (
            <div className="flex items-center justify-between gap-3 rounded-lg bg-muted p-3 text-sm">
                <p className="text-muted-foreground">
                    The balance could not be loaded.
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
        return <Skeleton aria-busy="true" className="h-24 w-full rounded-lg" />;
    }

    const { folio } = state;
    const billed = Number(folio.billedToCompany) > 0;

    return (
        <dl className="flex flex-col gap-1.5 rounded-lg bg-muted p-3 text-sm">
            <MoneyRow
                label={<RoomTotalLabel />}
                amount={folio.total}
                currency={currency}
            />
            <MoneyRow label="Paid" amount={folio.paid} currency={currency} />
            {billed && (
                <p className="text-xs text-muted-foreground">
                    +{' '}
                    <MoneyText
                        amount={folio.billedToCompany}
                        currency={currency}
                        plain
                    />{' '}
                    billed to company
                </p>
            )}
            <Separator className="my-1" />
            <MoneyRow
                label={
                    <>
                        <span className="font-medium">Balance</span>
                        {folio.depositHeld && (
                            <StatusBadge
                                status={folio.depositHeld}
                                dot={false}
                            />
                        )}
                    </>
                }
                amount={folio.balance}
                currency={currency}
                className={cn(
                    'font-medium',
                    Number(folio.balance) > 0 && 'text-destructive',
                )}
            />
        </dl>
    );
}

/**
 * A Sheet on the right with a stay at a glance: who, when, where, the balance flag, the other units of its group
 * booking, and a money box with the room total, paid and balance. The summary comes from the loaded stays; only the
 * money figures are asked for.
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
                                {stay.groupName && mates.length <= 1 && (
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
                            {mates.length > 1 && stay.groupName && (
                                <GroupUnitCards
                                    groupName={stay.groupName}
                                    mates={mates}
                                    currentUnitId={stay.unitId}
                                    rooms={rooms}
                                    onSelect={onSelect}
                                />
                            )}
                        </div>
                        {canViewReservations && (
                            <SheetFooter>
                                <FolioMoney
                                    url={folioRoute.url({
                                        reservation: stay.reservationId,
                                    })}
                                    currency={currency}
                                />
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
