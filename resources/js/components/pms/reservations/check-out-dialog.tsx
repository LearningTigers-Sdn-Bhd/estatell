import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import {
    AuditPendingNote,
    AuditPendingStrip,
} from '@/components/pms/reservations/audit-pending';
import { BillPanel } from '@/components/pms/reservations/check-out/bill-panel';
import { CollectSection } from '@/components/pms/reservations/check-out/collect-section';
import { DepositSection } from '@/components/pms/reservations/check-out/deposit-section';
import { EarlyDepartureSection } from '@/components/pms/reservations/check-out/early-departure-section';
import { useCheckOutForm } from '@/components/pms/reservations/check-out/use-check-out-form';
import { WhenGuestLeftCards } from '@/components/pms/reservations/check-out/when-guest-left-cards';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

type Unit = App.Data.Reservations.ReservationUnitData;
type Options = App.Data.Payments.PaymentFormOptionsData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
    paymentOptions?: Options;
    canWaiveFees: boolean;
    currency: App.Data.Property.CurrencyData | null;
};

function CheckOutForm({
    open,
    onOpenChange,
    reservationId,
    unit,
    paymentOptions,
    canWaiveFees,
    currency,
}: Props) {
    const state = useCheckOutForm({
        open,
        onOpenChange,
        reservationId,
        unitId: unit.id,
        paymentOptions,
        canWaiveFees,
    });
    const {
        form,
        preview,
        figures,
        early,
        mustChoose,
        needsReason,
        label,
        folioLink,
        others,
        moveLink,
        submit,
    } = state;
    const { errors } = form;
    const { owing, credit, charges, paid, settled, collecting } = figures;

    const bill =
        preview === null ? null : (
            <BillPanel
                preview={preview}
                figures={figures}
                currency={currency}
            />
        );

    return (
        <FormOverlay
            variant="dialog"
            size="lg"
            open={open}
            onOpenChange={onOpenChange}
            title={`${early !== null ? 'Early check-out' : 'Check out'} · ${unit.roomType}`}
            description={
                <>
                    {unit.primaryGuest ?? 'No primary guest'} ·{' '}
                    {unit.assigned.join(', ') || 'No room'} · departs{' '}
                    <DateText date={unit.departure} />
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel={label.charAt(0).toUpperCase() + label.slice(1)}
            processing={form.processing}
            submitDisabled={
                !settled ||
                mustChoose ||
                needsReason ||
                (collecting && paymentOptions === undefined)
            }
            loading={preview === null}
        >
            {preview !== null && (
                <>
                    <AuditPendingStrip pending={preview.auditPending} />
                    <Collapsible className="lg:hidden">
                        <CollapsibleTrigger className="group flex w-full items-center justify-between gap-4 rounded-lg border px-3 py-2 text-left text-sm">
                            <span className="flex flex-col">
                                <span className="font-medium">
                                    Bill ·{' '}
                                    {owing > 0 ? (
                                        <span className="text-destructive">
                                            To pay{' '}
                                            <MoneyText
                                                amount={owing.toFixed(2)}
                                                currency={currency}
                                            />
                                        </span>
                                    ) : credit > 0 ? (
                                        <>
                                            To refund{' '}
                                            <MoneyText
                                                amount={credit.toFixed(2)}
                                                currency={currency}
                                            />
                                        </>
                                    ) : (
                                        'Settled'
                                    )}
                                </span>
                                <span className="text-muted-foreground">
                                    Charges{' '}
                                    <MoneyText
                                        amount={charges.toFixed(2)}
                                        currency={currency}
                                    />{' '}
                                    · Paid{' '}
                                    <MoneyText
                                        amount={Math.abs(paid).toFixed(2)}
                                        currency={currency}
                                    />
                                </span>
                            </span>
                            <ChevronDown className="size-4 shrink-0 transition-transform group-aria-expanded:rotate-180" />
                        </CollapsibleTrigger>
                        <CollapsibleContent className="flex flex-col gap-4 pt-3">
                            {bill}
                        </CollapsibleContent>
                    </Collapsible>
                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] lg:gap-6">
                        <div className="flex min-w-0 flex-col gap-4">
                            <AuditPendingNote pending={preview.auditPending} />
                            <WhenGuestLeftCards
                                state={state}
                                currency={currency}
                            />
                            {preview.error !== null && (
                                <p className="text-sm text-destructive">
                                    {preview.error}
                                    {preview.receivesScheduled &&
                                        moveLink !== null && (
                                            <>
                                                {' '}
                                                <Link
                                                    href={moveLink}
                                                    className="underline underline-offset-4"
                                                >
                                                    Move charges
                                                </Link>
                                            </>
                                        )}
                                </p>
                            )}
                            <EarlyDepartureSection
                                state={state}
                                currency={currency}
                            />
                            {others.length > 0 &&
                                !preview.receivesScheduled && (
                                    <p className="text-sm text-muted-foreground">
                                        This folio pays for another room.
                                        Collect it all here, or move{' '}
                                        {others
                                            .map((room) => room.name)
                                            .join(', ')}
                                        's charges first.
                                        {moveLink !== null && (
                                            <>
                                                {' '}
                                                <Link
                                                    href={moveLink}
                                                    className="underline underline-offset-4"
                                                >
                                                    Move charges
                                                </Link>
                                            </>
                                        )}
                                    </p>
                                )}
                            {owing > 0 &&
                                !figures.canCollect &&
                                folioLink !== null && (
                                    <p className="text-sm text-muted-foreground">
                                        Collect the balance with Record payment
                                        on the{' '}
                                        <Link
                                            href={folioLink}
                                            className="underline underline-offset-4"
                                        >
                                            folio
                                        </Link>
                                        , then check out.
                                    </p>
                                )}
                            <DepositSection state={state} currency={currency} />
                            {figures.refundable > 0 && !preview.canRefund && (
                                <p className="text-sm text-muted-foreground">
                                    {state.canCorrectMoney
                                        ? 'A refund is due on this folio. Ask someone who may refund payments to give it back first.'
                                        : 'A refund is due on this folio. The owner has not allowed refunds during support access.'}
                                </p>
                            )}
                            <CollectSection
                                state={state}
                                paymentOptions={paymentOptions}
                                currency={currency}
                            />
                            {!settled && !collecting && owing === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    The folio is not at 0 yet, so check-out is
                                    not open.
                                </p>
                            )}
                        </div>
                        <div className="hidden min-w-0 flex-col gap-4 lg:flex">
                            {bill}
                        </div>
                    </div>
                </>
            )}
        </FormOverlay>
    );
}

/**
 * Check out one unit. Shows the unit folio, split by room when it also holds another room's charges, and the security
 * deposit held. The deposit is a credit, so the form uses
 * it toward the bill, refunds it, or keeps part of it for a damage charge that the check-out posts on the property's
 * built-in Damage charge item. For a user who may record payments, Collect
 * balance takes what is still owed, and the server records the payment, the refund and the check-out in one step.
 * Check-out opens once the folio will reach 0. The state lives in `useCheckOutForm` and the sections in `check-out/`.
 */
export function CheckOutDialog(props: Props) {
    return <CheckOutForm key={useFreshOnOpen(props.open)} {...props} />;
}
