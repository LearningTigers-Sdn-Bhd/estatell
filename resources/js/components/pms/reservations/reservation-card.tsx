import type { ReactNode } from 'react';
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
import { show } from '@/routes/reservations';
import {
    ReservationMarkers,
    SourceIcon,
    reservationSubtitle,
} from './reservation-parts';
import { RoomBox } from './room-box';

type Item = App.Data.Reservations.ReservationListItemData;

/**
 * One reservation as a card: room box, title with markers, stay strip, status, occupancy, room types and the money
 * strip (Total, Paid, Balance).
 * The title is a stretched link that opens the reservation.
 */
export function ReservationCard({ reservation }: { reservation: Item }) {
    return (
        <Card
            className={cn(
                'relative h-full',
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
                            <Link
                                href={show.url({ reservation: reservation.id })}
                                className="after:absolute after:inset-0"
                            >
                                {reservation.title}
                            </Link>
                        </h2>
                        <ReservationMarkers reservation={reservation} />
                    </div>
                    <p className="truncate text-sm text-muted-foreground">
                        {reservationSubtitle(reservation)}
                    </p>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger
                        render={
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                className="relative z-10"
                            />
                        }
                        aria-label={`Actions for ${reservation.reference}`}
                    >
                        <Ellipsis />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            render={
                                <Link
                                    href={show.url({
                                        reservation: reservation.id,
                                    })}
                                />
                            }
                        >
                            Open reservation
                        </DropdownMenuItem>
                        {reservation.bookerId !== null && (
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
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
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
                            {reservation.stayLabel ??
                                `${reservation.nights} ${reservation.nights === 1 ? 'night' : 'nights'}`}
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
                <dl className="mt-auto grid grid-cols-3 divide-x rounded-lg bg-muted py-2 text-sm">
                    <MoneyCell label="Total" amount={reservation.total} />
                    <MoneyCell
                        label="Paid"
                        amount={reservation.paid}
                        note={
                            Number(reservation.billedToCompany) > 0 ? (
                                <>
                                    +{' '}
                                    <MoneyText
                                        amount={reservation.billedToCompany}
                                        currency={null}
                                        plain
                                    />{' '}
                                    billed to company
                                </>
                            ) : undefined
                        }
                    />
                    <MoneyCell
                        label="Balance"
                        amount={reservation.balance}
                        owing
                    />
                </dl>
            </CardContent>
        </Card>
    );
}

/**
 * One column of the money strip: a muted label above the amount. With `owing`, an amount above 0 shows in the danger
 * tone.
 */
function MoneyCell({
    label,
    amount,
    owing = false,
    note,
}: {
    label: string;
    amount: string;
    owing?: boolean;
    note?: ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5 px-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={cn(
                    'font-medium',
                    owing && Number(amount) > 0 && 'text-destructive',
                )}
            >
                <MoneyText amount={amount} currency={null} plain />
            </dd>
            {note && <dd className="text-xs text-muted-foreground">{note}</dd>}
        </div>
    );
}
