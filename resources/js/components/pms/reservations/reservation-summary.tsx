import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarClock,
    BedDouble,
    Building2,
    CalendarPlus,
    HandCoins,
    Landmark,
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
import { AgentBillBreakdown } from '@/components/pms/payments/agent-bill-breakdown';
import { PaymentScheduleList } from '@/components/pms/payments/payment-schedule-list';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { show as showCompany } from '@/routes/finance/city-ledger/companies';
import { show as showGuest } from '@/routes/guests';
import { folio as folioTab } from '@/routes/reservations';

type Reservation = App.Data.Reservations.ReservationData;
type Money = App.Data.Reservations.ReservationMoneyData;

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
 * The side card of the reservation page: the booker, the money figures and the booking details.
 * The stay dates, Ref, source and Ext. ref sit in the page header, so they are not repeated here.
 * Phone, email and the link to the guest are sent only to staff who may view guests.
 */
export function ReservationSummary({
    reservation,
    money,
    paymentSchedule = null,
    agentBill = null,
    proofsWaiting = 0,
    canViewCompany,
    currency,
}: {
    reservation: Reservation;
    money: Money;
    paymentSchedule?: App.Data.Reservations.PaymentScheduleData | null;
    agentBill?: App.Data.Reservations.AgentBillData | null;
    /** Proofs the agent sent that staff have not checked; they are reviewed on the Folio tab. */
    proofsWaiting?: number;
    canViewCompany: boolean;
    currency: App.Data.Property.CurrencyData | null;
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
                <Row icon={Wallet} label="Total">
                    <MoneyText amount={money.total} currency={currency} />
                </Row>
                <Row icon={HandCoins} label="Paid">
                    <MoneyText amount={money.paid} currency={currency} />
                </Row>
                {Number(money.billedToCompany) > 0 && (
                    <Row icon={Landmark} label="Billed to company">
                        <MoneyText
                            amount={money.billedToCompany}
                            currency={currency}
                        />
                    </Row>
                )}
                <Row
                    icon={Scale}
                    label={money.isCredit ? 'Credit' : 'Still to pay'}
                >
                    <span
                        className={cn(
                            'font-semibold',
                            !money.isCredit &&
                                Number(money.stillToPay) > 0 &&
                                'text-destructive',
                        )}
                    >
                        <MoneyText
                            amount={money.stillToPay}
                            currency={currency}
                        />
                    </span>
                </Row>
            </dl>
            {(paymentSchedule !== null || proofsWaiting > 0) && (
                <>
                    <Separator />
                    <section className="flex flex-col gap-2">
                        <h2 className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                            <CalendarClock aria-hidden className="size-4" />
                            Payment schedule
                        </h2>
                        {agentBill !== null && (
                            <AgentBillBreakdown
                                bill={agentBill}
                                agentLabel="Agent pays"
                                currency={currency}
                            />
                        )}
                        {paymentSchedule !== null && (
                            <PaymentScheduleList
                                schedule={paymentSchedule}
                                currency={currency}
                            />
                        )}
                        {proofsWaiting > 0 && (
                            <Link
                                href={folioTab({ reservation: reservation.id })}
                                className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm hover:bg-muted/50"
                            >
                                <span className="flex items-center gap-2">
                                    <span
                                        aria-hidden
                                        className="size-2 rounded-full bg-info"
                                    />
                                    {proofsWaiting === 1
                                        ? '1 proof to check'
                                        : `${proofsWaiting} proofs to check`}
                                </span>
                                <span className="flex items-center gap-1 font-medium">
                                    Review
                                    <ArrowRight
                                        aria-hidden
                                        className="size-4"
                                    />
                                </span>
                            </Link>
                        )}
                    </section>
                </>
            )}
            <Separator />
            <dl className="flex flex-col gap-2">
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
                {reservation.unitCount > 1 && (
                    <Row icon={Receipt} label="Billing">
                        {reservation.billingModeLabel}
                    </Row>
                )}
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
