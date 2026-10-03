import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { buttonVariants } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { show as showGuest } from '@/routes/guests';

type Reservation = App.Data.Reservations.ReservationData;

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right">{children}</dd>
        </div>
    );
}

/**
 * The side card of the reservation page: the booker, the stay figures and the booking details.
 * Phone, email and the link to the guest are sent only to staff who may view guests.
 */
export function ReservationSummary({
    reservation,
    currency,
}: {
    reservation: Reservation;
    currency: App.Enums.Currency | null;
}) {
    const { booker } = reservation;

    return (
        <div className="flex flex-col gap-4">
            <section className="flex flex-col gap-1">
                <h2 className="text-sm font-medium text-muted-foreground">
                    Booker
                </h2>
                <p className="flex items-center gap-2 font-medium">
                    {booker.name}
                    {booker.isVip && (
                        <span className="text-xs text-warning">VIP</span>
                    )}
                    {booker.isBlacklisted && (
                        <span className="text-xs text-destructive">
                            Blacklisted
                        </span>
                    )}
                </p>
                {booker.phone && <p className="text-sm">{booker.phone}</p>}
                {booker.email && (
                    <p className="text-sm break-all">{booker.email}</p>
                )}
                {booker.id !== null && (
                    <Link
                        href={showGuest.url({ guest: booker.id })}
                        className={buttonVariants({
                            variant: 'outline',
                            size: 'sm',
                            className: 'mt-1 w-fit',
                        })}
                    >
                        Open guest
                    </Link>
                )}
            </section>
            <Separator />
            <dl className="flex flex-col gap-2">
                <Row label="Earliest arrival">
                    <DateText date={reservation.firstArrival} />
                </Row>
                <Row label="Latest departure">
                    <DateText date={reservation.lastDeparture} />
                </Row>
                <Row label="Units">
                    <span className="tabular-nums">
                        {reservation.unitCount}
                    </span>
                </Row>
                <Row label="Room nights">
                    <span className="tabular-nums">
                        {reservation.roomNights}
                    </span>
                </Row>
                <Row label="Stay total">
                    <MoneyText
                        amount={reservation.stayTotal}
                        currency={currency}
                    />
                </Row>
                <Row label="Balance">
                    {reservation.balance === null ? (
                        <span className="text-muted-foreground">—</span>
                    ) : (
                        <span
                            className={cn(
                                Number(reservation.balance) > 0 &&
                                    'text-destructive',
                            )}
                        >
                            <MoneyText
                                amount={reservation.balance}
                                currency={currency}
                            />
                        </span>
                    )}
                </Row>
            </dl>
            <Separator />
            <dl className="flex flex-col gap-2">
                {reservation.unitCount > 1 && (
                    <Row label="Billing">{reservation.billingModeLabel}</Row>
                )}
                <Row label="Source">{reservation.sourceLabel}</Row>
                <Row label="Company">—</Row>
                <Row label="Created">
                    <DateText date={reservation.createdOn} />
                    {reservation.createdBy && ` · ${reservation.createdBy}`}
                </Row>
            </dl>
            {reservation.notes && (
                <>
                    <Separator />
                    <section className="flex flex-col gap-1">
                        <h2 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Notes
                        </h2>
                        <p className="text-sm whitespace-pre-line">
                            {reservation.notes}
                        </p>
                    </section>
                </>
            )}
        </div>
    );
}
