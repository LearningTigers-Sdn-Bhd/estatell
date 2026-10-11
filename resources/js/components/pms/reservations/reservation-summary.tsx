import { Link } from '@inertiajs/react';
import {
    BedDouble,
    Building2,
    CalendarArrowDown,
    CalendarArrowUp,
    CalendarPlus,
    Globe,
    Mail,
    Moon,
    Phone,
    Receipt,
    Scale,
    StickyNote,
    User,
    UserRound,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { show as showCompany } from '@/routes/finance/city-ledger/companies';
import { show as showGuest } from '@/routes/guests';

type Reservation = App.Data.Reservations.ReservationData;

function Row({
    icon: Icon,
    label,
    children,
}: {
    icon: LucideIcon;
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-sm">
            <dt className="flex items-center gap-2 text-muted-foreground">
                <Icon aria-hidden className="size-4 shrink-0 self-center" />
                {label}
            </dt>
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
    canViewCompany,
    currency,
}: {
    reservation: Reservation;
    canViewCompany: boolean;
    currency: App.Enums.Currency | null;
}) {
    const { booker } = reservation;

    return (
        <div className="flex flex-col gap-4">
            <section className="flex flex-col gap-1 ps-3 pt-2">
                <h2 className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                    <User aria-hidden className="size-4" />
                    Booker
                </h2>
                <p className="flex items-center gap-2 font-medium">
                    {booker.id !== null ? (
                        <Link
                            href={showGuest.url({ guest: booker.id })}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="underline underline-offset-4"
                        >
                            {booker.name}
                        </Link>
                    ) : (
                        booker.name
                    )}
                    {booker.isVip && (
                        <span className="text-xs text-warning">VIP</span>
                    )}
                    {booker.isBlacklisted && (
                        <span className="text-xs text-destructive">
                            Blacklisted
                        </span>
                    )}
                </p>
                {booker.phone && (
                    <p className="flex items-center gap-2 text-sm">
                        <Phone
                            aria-hidden
                            className="size-4 text-muted-foreground"
                        />
                        {booker.phone}
                    </p>
                )}
                {booker.email && (
                    <p className="flex items-center gap-2 text-sm break-all">
                        <Mail
                            aria-hidden
                            className="size-4 shrink-0 text-muted-foreground"
                        />
                        {booker.email}
                    </p>
                )}
            </section>
            <Separator />
            <dl className="flex flex-col gap-2">
                <Row icon={CalendarArrowDown} label="Earliest arrival">
                    <DateText date={reservation.firstArrival} />
                </Row>
                <Row icon={CalendarArrowUp} label="Latest departure">
                    <DateText date={reservation.lastDeparture} />
                </Row>
                <Row icon={BedDouble} label="Units">
                    <span className="tabular-nums">
                        {reservation.unitCount}
                    </span>
                </Row>
                <Row
                    icon={Moon}
                    label={
                        reservation.stayLabel === null ? 'Room nights' : 'Stay'
                    }
                >
                    <span className="tabular-nums">
                        {reservation.stayLabel ?? reservation.roomNights}
                    </span>
                </Row>
                <Row icon={Wallet} label="Stay total">
                    <MoneyText
                        amount={reservation.stayTotal}
                        currency={currency}
                    />
                </Row>
                <Row icon={Scale} label="Balance">
                    {reservation.balance === null ? (
                        <span className="text-muted-foreground">No folio</span>
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
                    <Row icon={Receipt} label="Billing">
                        {reservation.billingModeLabel}
                    </Row>
                )}
                <Row icon={Globe} label="Source">
                    {reservation.sourceLabel}
                </Row>
                <Row icon={Building2} label="Company">
                    {reservation.companyId === null ? (
                        <span className="text-muted-foreground">None</span>
                    ) : canViewCompany ? (
                        <Link
                            href={showCompany.url({
                                company: reservation.companyId,
                            })}
                            className="underline underline-offset-2"
                        >
                            {reservation.companyName}
                        </Link>
                    ) : (
                        reservation.companyName
                    )}
                </Row>
                <Row icon={CalendarPlus} label="Created at">
                    <DateText date={reservation.createdOn} />
                </Row>
                <Row icon={UserRound} label="Created by">
                    {reservation.createdBy ?? 'System'}
                </Row>
            </dl>
            {reservation.notes && (
                <section className="flex h-32 flex-col gap-1 rounded-xl border p-3">
                    <h2 className="flex items-center gap-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        <StickyNote aria-hidden className="size-4" />
                        Notes
                    </h2>
                    <p className="min-h-0 flex-1 overflow-y-auto text-sm whitespace-pre-line">
                        {reservation.notes}
                    </p>
                </section>
            )}
        </div>
    );
}
