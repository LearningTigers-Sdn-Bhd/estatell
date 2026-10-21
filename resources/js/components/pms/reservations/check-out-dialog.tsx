import { Link, useForm } from '@inertiajs/react';
import { ChevronDown, DoorOpen, Moon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { DateText } from '@/components/pms/date-text';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { formatMoney, MoneyText } from '@/components/pms/money-text';
import {
    AuditPendingNote,
    AuditPendingStrip,
    PendingLines,
} from '@/components/pms/reservations/audit-pending';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { useCanCorrectMoney } from '@/hooks/use-is-impersonating';
import { cn } from '@/lib/utils';
import { folio } from '@/routes/reservations';
import { move as moveCharges } from '@/routes/reservations/folio';
import {
    checkOut,
    checkOutPreview,
    earlyCheckOut,
} from '@/routes/reservations/units';

type Unit = App.Data.Reservations.ReservationUnitData;
type Preview = App.Data.Reservations.UnitCheckOutPreviewData;
type Options = App.Data.Payments.PaymentFormOptionsData;
type DepositChoice = 'use' | 'refund';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
    paymentOptions?: Options;
    canWaiveFees: boolean;
    currency: App.Data.Property.CurrencyData | null;
};

function SummaryRow({
    label,
    amount,
    currency,
    negative = false,
}: {
    label: string;
    amount: number;
    currency: App.Data.Property.CurrencyData | null;
    negative?: boolean;
}) {
    return (
        <div className="flex justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span>
                {negative && '−'}
                <MoneyText amount={amount.toFixed(2)} currency={currency} />
            </span>
        </div>
    );
}

function CheckOutForm({
    open,
    onOpenChange,
    reservationId,
    unit,
    paymentOptions,
    canWaiveFees,
    currency,
}: Props) {
    const key = { reservation: reservationId, unit: unit.id };
    const [leftYesterday, setLeftYesterday] = useState(false);
    const [overdueChoice, setOverdueChoice] = useState<'' | 'left' | 'stayed'>(
        '',
    );
    const form = useForm({
        deposit: '',
        refund_amount: '',
        payment_method_id: '',
        reference: '',
        do_not_charge: false,
        reason: '',
        expected_fee: '',
        stayed_last_night: false,
        keep_damage: false,
        damage_amount: '',
        damage_note: '',
    });
    const { data, errors } = form;
    const damageTyped =
        data.keep_damage && /^\d+(\.\d{1,2})?$/.test(data.damage_amount)
            ? data.damage_amount
            : '';
    const { preview, reload } = useUnitPreview<Preview>(
        checkOutPreview.url(key),
        open,
        {
            ...(leftYesterday && { left_yesterday: '1' }),
            ...(overdueChoice === 'stayed' && { stayed_last_night: '1' }),
            ...(damageTyped !== '' && { damage_amount: damageTyped }),
        },
        data.keep_damage ? 300 : 0,
    );
    useFormOptions(
        open && preview?.canCollect === true,
        paymentOptions !== undefined,
        'paymentOptions',
    );
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const methods = (paymentOptions?.methods ?? []).filter(
        (one) => !one.isOldSystem,
    );
    const early = preview?.early ?? null;
    const overdue = preview?.overdue ?? null;
    const pendingDay = preview?.auditPending?.businessDate ?? '';
    // Display rules only: the server decides who is overdue and sends the dates as text.
    const mustChoose = overdue !== null && overdueChoice === '';
    const needsReason = leftYesterday && data.reason.trim() === '';
    const held = Number(preview?.depositHeld ?? 0);
    const refundable = Number(preview?.refundable ?? 0);
    const methodId = data.payment_method_id || String(methods[0]?.id ?? '');
    const method = methods.find((one) => String(one.id) === methodId);

    useEffect(() => {
        if (errors.expected_fee) {
            reload();
        }
        // Ask again only when the server says the fee changed.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errors.expected_fee]);

    const hasFee = early !== null && Number(early.fee) > 0;
    const showWaive = canWaiveFees && hasFee;
    const waived = showWaive && data.do_not_charge;
    // Display math only: the server posts the fee and checks the balance again.
    const feeOnFolio = early !== null && !waived ? Number(early.feeTotal) : 0;
    const damageItem = preview?.damageItem ?? null;
    // The damage posts first and the deposit offsets it. `damageTotal` is the server's figure with tax.
    const damaging = data.keep_damage && damageItem !== null && held > 0;
    const damageOnFolio =
        damaging && damageTyped !== '' ? Number(preview?.damageTotal ?? 0) : 0;
    const balance = Number(preview?.balance ?? 0) + feeOnFolio + damageOnFolio;
    // The folio block: charges (with the fee) + paid − deposit held = balance.
    const charges = Number(preview?.charges ?? 0) + feeOnFolio;
    const paid = Number(preview?.paid ?? 0);
    const owing = Math.max(balance, 0);
    const canCollect = preview?.canCollect === true;
    const canCorrectMoney = useCanCorrectMoney();
    const canRefund =
        preview?.canRefund === true && refundable > 0 && canCorrectMoney;
    // A folio in credit can only refund the deposit, so the choice is offered only while the guest owes money. With a
    // damage charge the deposit always goes to the damage and the bill first.
    const deposit = (
        owing > 0 ? (damaging ? 'use' : data.deposit || 'use') : 'refund'
    ) as DepositChoice;
    const refunding = canRefund && deposit === 'refund';
    // The folio credit (after the early departure fee, unless waived). Above the deposit, the credit is refunded as a
    // whole, from the payments in refund order. Mirrors CheckOutSettlement::refunds() on the server.
    const credit = Math.max(-balance, 0);
    const creditAbove = owing === 0 && credit > held;
    // A folio already at 0 has used the deposit toward the bill: there is nothing to refund.
    const depositUsed = held > 0 && Math.abs(balance) < 0.005;
    // Owing money: refund the whole deposit and collect the rest. Otherwise refund what settles the folio.
    const refundDefault =
        owing > 0
            ? held.toFixed(2)
            : Math.min(credit, creditAbove ? refundable : held).toFixed(2);
    // Above the deposit the amount is the credit and cannot change, so it is read only.
    const refundAmount = refunding
        ? creditAbove || data.refund_amount === ''
            ? refundDefault
            : data.refund_amount
        : '0.00';
    // The parts are the server's for its default; they do not apply when the amount differs (Do not charge).
    const refundParts =
        creditAbove && refundAmount === preview?.refundDefault
            ? (preview?.refundParts ?? [])
            : [];
    const refund = Number(refundAmount);
    const due = balance + refund;
    const collecting = canCollect && due > 0.005;
    const collectAmount = collecting ? due : 0;
    const remaining = due - collectAmount;
    // A ticked damage box needs an amount the server has priced.
    const damageReady = !damaging || preview?.damageTotal != null;
    const settled =
        preview !== null &&
        preview.error === null &&
        damageReady &&
        Math.abs(remaining) < 0.005;
    const verbs = [
        collecting && 'collect',
        refunding && refund > 0 && 'refund',
    ].filter(Boolean);
    const action = early !== null ? 'check out early' : 'check out';
    const label =
        verbs.length === 0 ? action : `${verbs.join(', ')} and ${action}`;
    const folioLink =
        preview?.folioKey != null
            ? folio.url(
                  { reservation: reservationId },
                  { query: { folio: preview.folioKey } },
              )
            : null;
    const others = preview?.rooms.filter((room) => !room.own) ?? [];
    // Another room's scheduled nights block check-out: the room's scheduled choice is on the page, nothing is ticked.
    const moveLink =
        preview?.canMoveCharges === true &&
        (preview.receivesScheduled || preview.otherLineIds.length > 0)
            ? moveCharges.url(
                  { reservation: reservationId },
                  preview.receivesScheduled
                      ? undefined
                      : { query: { lines: preview.otherLineIds } },
              )
            : null;

    const submit = () => {
        form.transform((values) => ({
            refund_amount: refunding ? refundAmount : '',
            collect_amount: collecting ? collectAmount.toFixed(2) : '',
            payment_method_id: collecting ? methodId : '',
            reference:
                collecting && method?.requiresReference ? values.reference : '',
            idempotency_key: idempotencyKey,
            damage_amount: damaging ? values.damage_amount : '',
            damage_note: damaging ? values.damage_note : '',
            ...(early !== null && {
                do_not_charge: waived,
                reason: values.reason,
                expected_fee: early.fee,
                left_yesterday: leftYesterday,
            }),
            ...(overdueChoice === 'stayed' && { stayed_last_night: true }),
        }));
        form.post((early !== null ? earlyCheckOut : checkOut).url(key), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    const bill =
        preview === null ? null : (
            <>
                <PendingLines
                    lines={preview.pendingLines}
                    currency={currency}
                />
                <div className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">Unit folio</span>
                    <SummaryRow
                        label="Charges"
                        amount={charges}
                        currency={currency}
                    />
                    {Math.abs(paid) > 0.005 && (
                        <SummaryRow
                            label="Paid"
                            amount={Math.abs(paid)}
                            currency={currency}
                            negative={paid < 0}
                        />
                    )}
                    {damageOnFolio > 0 && (
                        <SummaryRow
                            label="Damage"
                            amount={damageOnFolio}
                            currency={currency}
                        />
                    )}
                    {held > 0 && (
                        <SummaryRow
                            label="Deposit held"
                            amount={held}
                            currency={currency}
                            negative
                        />
                    )}
                    <div className="flex justify-between gap-4 font-medium">
                        <span>
                            {owing > 0
                                ? 'To pay'
                                : credit > 0
                                  ? 'Credit'
                                  : 'Balance'}
                        </span>
                        {owing > 0 ? (
                            <span className="text-destructive">
                                <MoneyText
                                    amount={owing.toFixed(2)}
                                    currency={currency}
                                />
                            </span>
                        ) : credit > 0 ? (
                            <MoneyText
                                amount={credit.toFixed(2)}
                                currency={currency}
                            />
                        ) : (
                            <span>Settled</span>
                        )}
                    </div>
                    {preview.rooms.map((room) => (
                        <div
                            key={room.name}
                            className="flex justify-between gap-4 pl-4"
                        >
                            <span className="text-muted-foreground">
                                {room.name}
                            </span>
                            <MoneyText
                                amount={room.amount}
                                currency={currency}
                            />
                        </div>
                    ))}
                </div>
            </>
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
                            {overdue !== null && (
                                <ChoiceCards
                                    id="check-out-overdue"
                                    legend={`This guest was due to leave yesterday (${pendingDay}). What happened?`}
                                    choices={[
                                        {
                                            value: 'left',
                                            label: 'They left yesterday',
                                            description: `Check out on ${pendingDay}. No extra night.`,
                                            icon: DoorOpen,
                                        },
                                        {
                                            value: 'stayed',
                                            label: 'They stayed last night',
                                            description: `Add ${pendingDay} (${formatMoney(overdue.nightTotal, currency)}), then check out today.`,
                                            icon: Moon,
                                        },
                                    ]}
                                    value={overdueChoice}
                                    onValueChange={(value) =>
                                        setOverdueChoice(
                                            value as 'left' | 'stayed',
                                        )
                                    }
                                    error={errors.stayed_last_night}
                                />
                            )}
                            {(leftYesterday ||
                                early?.leftYesterdayAvailable === true) && (
                                <button
                                    type="button"
                                    className="self-start text-sm underline underline-offset-4"
                                    onClick={() =>
                                        setLeftYesterday(!leftYesterday)
                                    }
                                >
                                    {leftYesterday
                                        ? 'Back to checking out today'
                                        : `Guest actually left yesterday (${pendingDay})? ›`}
                                </button>
                            )}
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
                            {early !== null && (
                                <>
                                    <div className="flex flex-col gap-1 text-sm">
                                        <div className="flex justify-between gap-4">
                                            <span className="text-muted-foreground">
                                                Booked departure
                                            </span>
                                            <DateText date={early.departure} />
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <span className="text-muted-foreground">
                                                Nights removed
                                            </span>
                                            <span>{early.nightsRemoved}</span>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <span className="text-muted-foreground">
                                                Early departure fee
                                            </span>
                                            <span className="font-medium">
                                                {hasFee ? (
                                                    <>
                                                        <MoneyText
                                                            amount={early.fee}
                                                            currency={currency}
                                                        />{' '}
                                                        <span className="font-normal text-muted-foreground">
                                                            + tax
                                                        </span>
                                                    </>
                                                ) : (
                                                    'No charge'
                                                )}
                                            </span>
                                        </div>
                                    </div>
                                    <FieldError>
                                        {errors.expected_fee}
                                    </FieldError>
                                    {showWaive && (
                                        <FieldLabel htmlFor="check-out-do_not_charge">
                                            <Field orientation="horizontal">
                                                <Checkbox
                                                    id="check-out-do_not_charge"
                                                    checked={data.do_not_charge}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        form.setData(
                                                            'do_not_charge',
                                                            checked,
                                                        )
                                                    }
                                                />
                                                <FieldContent>
                                                    <span className="text-sm font-medium">
                                                        Do not charge
                                                    </span>
                                                    <FieldDescription>
                                                        The fee is not posted to
                                                        the folio. This is
                                                        logged with your name.
                                                    </FieldDescription>
                                                </FieldContent>
                                            </Field>
                                        </FieldLabel>
                                    )}
                                    <Field data-invalid={!!errors.reason}>
                                        <FieldLabel htmlFor="check-out-reason">
                                            {leftYesterday
                                                ? 'Reason (required)'
                                                : 'Reason'}
                                        </FieldLabel>
                                        <Textarea
                                            id="check-out-reason"
                                            value={data.reason}
                                            onChange={(event) =>
                                                form.setData(
                                                    'reason',
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={500}
                                            rows={2}
                                            placeholder={
                                                waived
                                                    ? 'Required with Do not charge'
                                                    : leftYesterday
                                                      ? 'e.g. Guest left late last night and did not check out'
                                                      : 'e.g. Flight changed, guest leaves a day early'
                                            }
                                            aria-invalid={!!errors.reason}
                                        />
                                        <FieldError>{errors.reason}</FieldError>
                                    </Field>
                                </>
                            )}
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
                            {owing > 0 && !canCollect && folioLink !== null && (
                                <p className="text-sm text-muted-foreground">
                                    Collect the balance with Record payment on
                                    the{' '}
                                    <Link
                                        href={folioLink}
                                        className="underline underline-offset-4"
                                    >
                                        folio
                                    </Link>
                                    , then check out.
                                </p>
                            )}
                            {canRefund && (
                                <FieldSet>
                                    <FieldLegend variant="label">
                                        {held > 0
                                            ? 'Security deposit'
                                            : 'Credit'}
                                    </FieldLegend>
                                    {depositUsed && (
                                        <p className="text-sm text-muted-foreground">
                                            {formatMoney(
                                                held.toFixed(2),
                                                currency,
                                            )}{' '}
                                            {damaging
                                                ? 'kept for damages.'
                                                : 'used toward the bill.'}{' '}
                                            Nothing to refund.
                                        </p>
                                    )}
                                    {held > 0 &&
                                        owing === 0 &&
                                        damageItem === null &&
                                        folioLink !== null && (
                                            <Link
                                                href={folioLink}
                                                className="text-sm underline underline-offset-4"
                                            >
                                                Keep it for damages? ›
                                            </Link>
                                        )}
                                    {held > 0 && owing > 0 && !damaging && (
                                        <Field orientation="horizontal">
                                            <Checkbox
                                                id="check-out-use-deposit"
                                                checked={deposit === 'use'}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        'deposit',
                                                        checked
                                                            ? 'use'
                                                            : 'refund',
                                                    )
                                                }
                                            />
                                            <FieldContent>
                                                <FieldLabel htmlFor="check-out-use-deposit">
                                                    Use{' '}
                                                    {formatMoney(
                                                        held.toFixed(2),
                                                        currency,
                                                    )}{' '}
                                                    toward the bill
                                                </FieldLabel>
                                                {damageItem === null &&
                                                    folioLink !== null && (
                                                        <Link
                                                            href={folioLink}
                                                            className="text-sm underline underline-offset-4"
                                                        >
                                                            Keep it for damages?
                                                            ›
                                                        </Link>
                                                    )}
                                            </FieldContent>
                                        </Field>
                                    )}
                                    {held > 0 && damageItem !== null && (
                                        <>
                                            <Field orientation="horizontal">
                                                <Checkbox
                                                    id="check-out-damage"
                                                    checked={data.keep_damage}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        form.setData(
                                                            (current) => ({
                                                                ...current,
                                                                keep_damage:
                                                                    checked,
                                                                damage_amount:
                                                                    current.damage_amount ||
                                                                    (damageItem.defaultAmount ??
                                                                        ''),
                                                            }),
                                                        )
                                                    }
                                                />
                                                <FieldLabel htmlFor="check-out-damage">
                                                    Keep part of it for damages
                                                </FieldLabel>
                                            </Field>
                                            {damaging && (
                                                <div className="grid gap-4 sm:grid-cols-2">
                                                    <Field
                                                        data-invalid={
                                                            !!errors.damage_amount
                                                        }
                                                    >
                                                        <FieldLabel htmlFor="check-out-damage_amount">
                                                            Damage
                                                        </FieldLabel>
                                                        <MoneyInput
                                                            id="check-out-damage_amount"
                                                            value={
                                                                data.damage_amount
                                                            }
                                                            onChange={(value) =>
                                                                form.setData(
                                                                    'damage_amount',
                                                                    value,
                                                                )
                                                            }
                                                            currency={currency}
                                                            placeholder="e.g. 50.00"
                                                            invalid={
                                                                !!errors.damage_amount
                                                            }
                                                        />
                                                        <FieldDescription>
                                                            Posts to{' '}
                                                            {damageItem.name},
                                                            plus its tax.
                                                        </FieldDescription>
                                                        <FieldError>
                                                            {
                                                                errors.damage_amount
                                                            }
                                                        </FieldError>
                                                    </Field>
                                                    <Field
                                                        data-invalid={
                                                            !!errors.damage_note
                                                        }
                                                    >
                                                        <FieldLabel htmlFor="check-out-damage_note">
                                                            Note
                                                        </FieldLabel>
                                                        <Input
                                                            id="check-out-damage_note"
                                                            value={
                                                                data.damage_note
                                                            }
                                                            onChange={(event) =>
                                                                form.setData(
                                                                    'damage_note',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            maxLength={100}
                                                            placeholder="e.g. Broken lamp"
                                                            autoComplete="off"
                                                            aria-invalid={
                                                                !!errors.damage_note
                                                            }
                                                        />
                                                        <FieldError>
                                                            {errors.damage_note}
                                                        </FieldError>
                                                    </Field>
                                                </div>
                                            )}
                                        </>
                                    )}
                                    {deposit === 'refund' && !depositUsed && (
                                        <div className="flex flex-col gap-2 text-sm">
                                            <span className="text-muted-foreground">
                                                {creditAbove
                                                    ? 'Refund credit'
                                                    : 'Refund deposit'}
                                                {preview.depositMethod
                                                    ? ` · ${preview.depositMethod}`
                                                    : ''}
                                            </span>
                                            {creditAbove ? (
                                                <div className="flex flex-col gap-1">
                                                    {refundParts.map(
                                                        (part, index) => (
                                                            <div
                                                                key={index}
                                                                className="flex justify-between gap-4"
                                                            >
                                                                <span className="text-muted-foreground">
                                                                    {
                                                                        part.purpose
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {
                                                                        part.method
                                                                    }
                                                                    {part.reference
                                                                        ? ` ref ${part.reference}`
                                                                        : ''}
                                                                </span>
                                                                <MoneyText
                                                                    amount={
                                                                        part.amount
                                                                    }
                                                                    currency={
                                                                        currency
                                                                    }
                                                                />
                                                            </div>
                                                        ),
                                                    )}
                                                    <FieldError>
                                                        {errors.refund_amount}
                                                    </FieldError>
                                                </div>
                                            ) : (
                                                <Field
                                                    data-invalid={
                                                        !!errors.refund_amount
                                                    }
                                                >
                                                    <MoneyInput
                                                        id="check-out-refund_amount"
                                                        value={refundAmount}
                                                        onChange={(value) =>
                                                            form.setData(
                                                                'refund_amount',
                                                                value,
                                                            )
                                                        }
                                                        currency={currency}
                                                        invalid={
                                                            !!errors.refund_amount
                                                        }
                                                    />
                                                    <FieldError>
                                                        {errors.refund_amount}
                                                    </FieldError>
                                                </Field>
                                            )}
                                        </div>
                                    )}
                                </FieldSet>
                            )}
                            {refundable > 0 && !preview.canRefund && (
                                <p className="text-sm text-muted-foreground">
                                    {canCorrectMoney
                                        ? 'A refund is due on this folio. Ask someone who may refund payments to give it back first.'
                                        : 'A refund is due on this folio. The owner has not allowed refunds during support access.'}
                                </p>
                            )}
                            {collecting && (
                                <div className="flex flex-col gap-4">
                                    <div className="flex justify-between gap-4 text-sm font-medium">
                                        Collect balance
                                        <MoneyText
                                            amount={collectAmount.toFixed(2)}
                                            currency={currency}
                                        />
                                    </div>
                                    <div
                                        className={cn(
                                            'grid gap-4',
                                            method?.requiresReference &&
                                                'sm:grid-cols-2',
                                        )}
                                    >
                                        <Field
                                            data-invalid={
                                                !!errors.payment_method_id
                                            }
                                        >
                                            <FieldLabel htmlFor="check-out-payment_method_id">
                                                Method
                                            </FieldLabel>
                                            {paymentOptions === undefined ? (
                                                <Skeleton className="h-9 w-full" />
                                            ) : (
                                                <EnumSelect
                                                    id="check-out-payment_method_id"
                                                    options={methods.map(
                                                        (one) => ({
                                                            value: String(
                                                                one.id,
                                                            ),
                                                            label: one.name,
                                                        }),
                                                    )}
                                                    value={methodId}
                                                    onValueChange={(value) =>
                                                        form.setData(
                                                            'payment_method_id',
                                                            value,
                                                        )
                                                    }
                                                    aria-invalid={
                                                        !!errors.payment_method_id
                                                    }
                                                />
                                            )}
                                            <FieldError>
                                                {errors.payment_method_id}
                                            </FieldError>
                                        </Field>
                                        {method?.requiresReference && (
                                            <Field
                                                data-invalid={
                                                    !!errors.reference
                                                }
                                            >
                                                <FieldLabel htmlFor="check-out-reference">
                                                    Reference
                                                </FieldLabel>
                                                <Input
                                                    id="check-out-reference"
                                                    value={data.reference}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'reference',
                                                            event.target.value,
                                                        )
                                                    }
                                                    maxLength={100}
                                                    autoComplete="off"
                                                    aria-invalid={
                                                        !!errors.reference
                                                    }
                                                />
                                                <FieldError>
                                                    {errors.reference}
                                                </FieldError>
                                            </Field>
                                        )}
                                    </div>
                                </div>
                            )}
                            {(collecting ||
                                (refunding && refund > 0) ||
                                damageOnFolio > 0) && (
                                <>
                                    <Separator />
                                    <div className="flex flex-col gap-1 text-sm">
                                        {damageOnFolio > 0 && (
                                            <SummaryRow
                                                label="Damage"
                                                amount={damageOnFolio}
                                                currency={currency}
                                            />
                                        )}
                                        {collecting && (
                                            <SummaryRow
                                                label={`Collect${method ? ` by ${method.name}` : ''}`}
                                                amount={collectAmount}
                                                currency={currency}
                                            />
                                        )}
                                        {refunding && refund > 0 && (
                                            <SummaryRow
                                                label={`Refund${preview.depositMethod ? ` by ${preview.depositMethod}` : ''}`}
                                                amount={refund}
                                                currency={currency}
                                                negative
                                            />
                                        )}
                                        <div className="flex justify-between gap-4 font-medium">
                                            <span>
                                                Remaining after check-out
                                            </span>
                                            <span
                                                className={cn(
                                                    !settled &&
                                                        'text-destructive',
                                                )}
                                            >
                                                <MoneyText
                                                    amount={remaining.toFixed(
                                                        2,
                                                    )}
                                                    currency={currency}
                                                />
                                            </span>
                                        </div>
                                    </div>
                                </>
                            )}
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
 * damage charge item (or, without one, links to the folio to post it first). For a user who may record payments, Collect
 * balance takes what is still owed, and the server records the payment, the refund and the check-out in one step.
 * Check-out opens once the folio will reach 0.
 */
export function CheckOutDialog(props: Props) {
    return <CheckOutForm key={useFreshOnOpen(props.open)} {...props} />;
}
