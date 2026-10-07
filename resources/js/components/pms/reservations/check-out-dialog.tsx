import { Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import { folio } from '@/routes/reservations';
import {
    checkOut,
    checkOutPreview,
    earlyCheckOut,
} from '@/routes/reservations/units';

type Unit = App.Data.Reservations.ReservationUnitData;
type Preview = App.Data.Reservations.UnitCheckOutPreviewData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
    canWaiveFees: boolean;
    currency: App.Enums.Currency | null;
};

function CheckOutForm({
    open,
    onOpenChange,
    reservationId,
    unit,
    canWaiveFees,
    currency,
}: Props) {
    const key = { reservation: reservationId, unit: unit.id };
    const { preview, reload } = useUnitPreview<Preview>(
        checkOutPreview.url(key),
        open,
    );
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const form = useForm({
        deposit: 'refund',
        refund_amount: '',
        do_not_charge: false,
        reason: '',
        expected_fee: '',
    });
    const { data, errors } = form;
    const early = preview?.early ?? null;
    const held = Number(preview?.depositHeld ?? 0);

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
    const balance = Number(preview?.balance ?? 0) + feeOnFolio;
    const owing = Math.max(balance, 0);
    const refunding =
        preview?.canRefund === true && held > 0 && data.deposit === 'refund';
    const refundDefault =
        early !== null
            ? Math.min(held, Math.max(-balance, 0)).toFixed(2)
            : (preview?.refundDefault ?? '0.00');
    const amount =
        data.refund_amount !== '' ? data.refund_amount : refundDefault;
    const remaining = balance + (refunding ? Number(amount) : 0);
    const settled =
        preview !== null &&
        preview.error === null &&
        Math.abs(remaining) < 0.005;

    const submit = () => {
        form.transform((values) => ({
            refund_amount: refunding ? amount : '',
            idempotency_key: idempotencyKey,
            ...(early !== null && {
                do_not_charge: waived,
                reason: values.reason,
                expected_fee: early.fee,
            }),
        }));
        form.post((early !== null ? earlyCheckOut : checkOut).url(key), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
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
            submitLabel={early !== null ? 'Check out early' : 'Check out'}
            processing={form.processing}
            submitDisabled={!settled}
            loading={preview === null}
        >
            {preview !== null && (
                <>
                    {preview.error !== null && (
                        <p className="text-sm text-destructive">
                            {preview.error}
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
                            <FieldError>{errors.expected_fee}</FieldError>
                            {showWaive && (
                                <FieldLabel htmlFor="check-out-do_not_charge">
                                    <Field orientation="horizontal">
                                        <Checkbox
                                            id="check-out-do_not_charge"
                                            checked={data.do_not_charge}
                                            onCheckedChange={(checked) =>
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
                                                The fee is not posted to the
                                                folio. This is logged with your
                                                name.
                                            </FieldDescription>
                                        </FieldContent>
                                    </Field>
                                </FieldLabel>
                            )}
                            <Field data-invalid={!!errors.reason}>
                                <FieldLabel htmlFor="check-out-reason">
                                    Reason
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
                                            : 'Optional'
                                    }
                                    aria-invalid={!!errors.reason}
                                />
                                <FieldError>{errors.reason}</FieldError>
                            </Field>
                        </>
                    )}
                    <div className="flex flex-col gap-1 text-sm">
                        <div className="flex justify-between gap-4">
                            <span className="text-muted-foreground">
                                Unit folio
                            </span>
                            <span>
                                {owing > 0 ? (
                                    <span className="text-destructive">
                                        <MoneyText
                                            amount={owing.toFixed(2)}
                                            currency={currency}
                                        />{' '}
                                        to pay
                                    </span>
                                ) : held > 0 ? (
                                    'Only the deposit is left'
                                ) : (
                                    'Settled'
                                )}
                            </span>
                        </div>
                        {held > 0 && (
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Deposit held
                                </span>
                                <MoneyText
                                    amount={preview.depositHeld}
                                    currency={currency}
                                />
                            </div>
                        )}
                    </div>
                    {owing > 0 && preview.folioKey !== null && (
                        <p className="text-sm text-muted-foreground">
                            Collect the balance with Record payment on the{' '}
                            <Link
                                href={folio.url(
                                    { reservation: reservationId },
                                    { query: { folio: preview.folioKey } },
                                )}
                                className="underline underline-offset-4"
                            >
                                folio
                            </Link>
                            , then check out.
                        </p>
                    )}
                    {held > 0 && preview.canRefund && (
                        <RadioGroup
                            value={data.deposit}
                            onValueChange={(value) =>
                                form.setData('deposit', value)
                            }
                        >
                            <Field orientation="horizontal">
                                <RadioGroupItem
                                    id="check-out-refund"
                                    value="refund"
                                />
                                <FieldContent>
                                    <FieldLabel htmlFor="check-out-refund">
                                        Refund deposit
                                        {preview.depositMethod
                                            ? ` · ${preview.depositMethod}`
                                            : ''}
                                    </FieldLabel>
                                    {data.deposit === 'refund' && (
                                        <Field
                                            data-invalid={
                                                !!errors.refund_amount
                                            }
                                        >
                                            <MoneyInput
                                                id="check-out-refund_amount"
                                                value={amount}
                                                onChange={(value) =>
                                                    form.setData(
                                                        'refund_amount',
                                                        value,
                                                    )
                                                }
                                                currency={currency}
                                                invalid={!!errors.refund_amount}
                                            />
                                            <FieldError>
                                                {errors.refund_amount}
                                            </FieldError>
                                        </Field>
                                    )}
                                </FieldContent>
                            </Field>
                            <Field orientation="horizontal">
                                <RadioGroupItem
                                    id="check-out-keep"
                                    value="keep"
                                />
                                <FieldContent>
                                    <FieldLabel htmlFor="check-out-keep">
                                        Keep for damages
                                    </FieldLabel>
                                    <FieldDescription>
                                        Post a charge on the folio first. The
                                        deposit offsets it, then refund the
                                        rest.
                                    </FieldDescription>
                                    {data.deposit === 'keep' &&
                                        preview.folioKey !== null && (
                                            <Link
                                                href={folio.url(
                                                    {
                                                        reservation:
                                                            reservationId,
                                                    },
                                                    {
                                                        query: {
                                                            folio: preview.folioKey,
                                                        },
                                                    },
                                                )}
                                                className="text-sm underline underline-offset-4"
                                            >
                                                Open the folio to post the
                                                charge
                                            </Link>
                                        )}
                                </FieldContent>
                            </Field>
                        </RadioGroup>
                    )}
                    {held > 0 && !preview.canRefund && (
                        <p className="text-sm text-muted-foreground">
                            A deposit is held. Ask someone who may refund
                            payments to give it back first.
                        </p>
                    )}
                    {!settled && owing === 0 && (
                        <p className="text-sm text-muted-foreground">
                            The folio is not at 0 yet, so check-out is not open.
                        </p>
                    )}
                </>
            )}
        </FormOverlay>
    );
}

/**
 * Check out one unit. Shows the unit folio and the security deposit held. The deposit is a credit, so the form
 * refunds it (or leaves it for a damage charge) and opens check-out once the folio reaches 0.
 */
export function CheckOutDialog(props: Props) {
    return <CheckOutForm key={useFreshOnOpen(props.open)} {...props} />;
}
