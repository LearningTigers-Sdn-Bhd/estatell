import {
    BedDouble,
    CalendarX,
    HandCoins,
    Moon,
    Scale,
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

type Booking = App.Data.Portal.PortalBookingData;

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
            <dt className="flex shrink-0 items-center gap-2 text-muted-foreground">
                <Icon aria-hidden className="size-4 shrink-0 self-center" />
                {label}
            </dt>
            <dd className="min-w-0 text-right wrap-break-word">{children}</dd>
        </div>
    );
}

/**
 * The side card of the portal booking page: the booker, what the agent and the guest pay, what is paid and still to
 * pay, the rooms and nights, and the cancellation terms. The layout follows the staff reservation summary. The stay
 * dates and references sit in the page header, so they are not repeated here.
 */
export function BookingSummaryCard({
    booking,
    bill,
    schedule,
    currency,
}: {
    booking: Booking;
    bill: App.Data.Reservations.AgentBillData;
    schedule: App.Data.Reservations.PaymentScheduleData | null;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const roomNights = booking.units.reduce(
        (sum, unit) => sum + unit.nights,
        0,
    );

    return (
        <div className="flex flex-col gap-4">
            <section className="flex flex-col gap-1 ps-3 pt-2">
                <h2 className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                    <User aria-hidden className="size-4" />
                    Booker
                </h2>
                <p className="font-medium">{booking.bookerName}</p>
            </section>
            <Separator />
            <dl className="flex flex-col gap-2">
                <Row icon={Wallet} label="You pay">
                    <MoneyText amount={bill.agentTotal} currency={currency} />
                </Row>
                {Number(bill.guestTotal) > 0 && (
                    <Row icon={UserRound} label="Guest pays">
                        <MoneyText
                            amount={bill.guestTotal}
                            currency={currency}
                        />
                    </Row>
                )}
                {schedule !== null && (
                    <>
                        <Row icon={HandCoins} label="Paid">
                            <MoneyText
                                amount={schedule.paid}
                                currency={currency}
                            />
                        </Row>
                        <Row icon={Scale} label="Still to pay">
                            <span
                                className={cn(
                                    'font-semibold',
                                    Number(schedule.left) > 0 &&
                                        'text-destructive',
                                )}
                            >
                                <MoneyText
                                    amount={schedule.left}
                                    currency={currency}
                                />
                            </span>
                        </Row>
                    </>
                )}
            </dl>
            <Separator />
            <dl className="flex flex-col gap-2">
                <Row icon={BedDouble} label="Units">
                    <span className="tabular-nums">{booking.units.length}</span>
                </Row>
                <Row icon={Moon} label="Room nights">
                    <span className="tabular-nums">{roomNights}</span>
                </Row>
                <Row icon={CalendarX} label="Cancellation">
                    {booking.cancellationRequested ? (
                        'Requested'
                    ) : booking.cancelMode === 'free' && booking.freeUntil ? (
                        <>
                            Free until <DateText date={booking.freeUntil} />
                        </>
                    ) : (
                        <span className="text-muted-foreground">
                            Through the property
                        </span>
                    )}
                </Row>
            </dl>
        </div>
    );
}
