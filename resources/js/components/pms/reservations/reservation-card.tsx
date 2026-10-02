import { Link } from '@inertiajs/react';
import { Baby, Ellipsis, User } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { show as showGuest } from '@/routes/guests';
import {
    ReservationMarkers,
    SourceIcon,
    reservationSubtitle,
} from './reservation-parts';
import { RoomBox } from './room-box';

type Item = App.Data.Reservations.ReservationListItemData;

/**
 * One reservation as a card: room box, title with markers, stay strip, status, occupancy, room types and total.
 * Opening the reservation comes with the reservation page.
 */
export function ReservationCard({
    reservation,
    currency,
}: {
    reservation: Item;
    currency: App.Enums.Currency | null;
}) {
    return (
        <Card
            className={cn(
                'h-full',
                reservation.isCancelled && 'bg-muted/50 text-muted-foreground',
                reservation.isBlacklisted &&
                    'bg-muted ring-2 ring-destructive/50',
            )}
        >
            <CardHeader className="flex flex-row items-start gap-3">
                <RoomBox box={reservation.roomBox} />
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <div className="flex min-w-0 items-center gap-1.5">
                        <h2 className="truncate font-heading text-base leading-snug font-medium">
                            {reservation.title}
                        </h2>
                        <ReservationMarkers reservation={reservation} />
                    </div>
                    <p className="truncate text-sm text-muted-foreground">
                        {reservationSubtitle(reservation)}
                    </p>
                </div>
                {reservation.bookerId !== null && (
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            render={<Button variant="ghost" size="icon-sm" />}
                            aria-label={`Actions for ${reservation.reference}`}
                        >
                            <Ellipsis />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                render={
                                    <Link
                                        href={showGuest.url({
                                            guest: reservation.bookerId,
                                        })}
                                    />
                                }
                            >
                                Open guest
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </CardHeader>
            <CardContent className="flex flex-1 flex-col gap-3">
                <dl className="grid grid-cols-3 gap-2 rounded-lg bg-muted px-3 py-2 text-sm">
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Arrival
                        </dt>
                        <dd>
                            <DateText date={reservation.firstArrival} />
                        </dd>
                    </div>
                    <div className="text-center">
                        <dt className="text-xs text-muted-foreground">Stay</dt>
                        <dd className="tabular-nums">
                            {reservation.nights}{' '}
                            {reservation.nights === 1 ? 'night' : 'nights'}
                        </dd>
                    </div>
                    <div className="text-right">
                        <dt className="text-xs text-muted-foreground">
                            Departure
                        </dt>
                        <dd>
                            <DateText date={reservation.lastDeparture} />
                        </dd>
                    </div>
                </dl>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    <StatusBadge status={reservation.status} />
                    <SourceIcon reservation={reservation} />
                    {reservation.isGroup && (
                        <span className="tabular-nums">
                            {reservation.unitCount} units
                        </span>
                    )}
                    <span className="ml-auto flex items-center gap-3 tabular-nums">
                        <span
                            className="flex items-center gap-1"
                            aria-label={`${reservation.adults} adults`}
                        >
                            <User aria-hidden className="size-4" />
                            {reservation.adults}
                        </span>
                        <span
                            className="flex items-center gap-1"
                            aria-label={`${reservation.children} children`}
                        >
                            <Baby aria-hidden className="size-4" />
                            {reservation.children}
                        </span>
                    </span>
                </div>
                <dl className="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 text-sm">
                    <dt className="text-xs text-muted-foreground">Room type</dt>
                    <dt className="text-right text-xs text-muted-foreground">
                        Booked
                    </dt>
                    <dd>{reservation.roomTypes.join(', ')}</dd>
                    <dd className="text-right">
                        <DateText date={reservation.bookedOn} />
                    </dd>
                </dl>
                <div className="mt-auto flex items-center justify-between rounded-lg bg-muted px-3 py-2 text-sm">
                    <span className="text-muted-foreground">Total</span>
                    <span className="font-medium">
                        <MoneyText
                            amount={reservation.total}
                            currency={currency}
                        />
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}
