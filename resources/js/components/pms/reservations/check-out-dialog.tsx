import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { folio } from '@/routes/reservations';
import { checkOut, checkOutPreview } from '@/routes/reservations/units';

type Unit = App.Data.Reservations.ReservationUnitData;
type Preview = App.Data.Reservations.UnitCheckOutPreviewData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
    currency: App.Enums.Currency | null;
};

function CheckOutForm({
    open,
    onOpenChange,
    reservationId,
    unit,
    currency,
}: Props) {
    const key = { reservation: reservationId, unit: unit.id };
    const { preview } = useUnitPreview<Preview>(checkOutPreview.url(key), open);
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const form = useForm({
        deposit: 'refund',
        refund_amount: '',
    });
    const { data, errors } = form;
    const held = Number(preview?.depositHeld ?? 0);
    const refunding =
        preview?.canRefund === true && held > 0 && data.deposit === 'refund';
    const amount =
        data.refund_amount !== ''
            ? data.refund_amount
            : (preview?.refundDefault ?? '0.00');
    const remaining =
        Number(preview?.balance ?? 0) + (refunding ? Number(amount) : 0);
    const settled =
        preview !== null &&
        preview.error === null &&
        Math.abs(remaining) < 0.005;

    const submit = () => {
        form.transform(() => ({
            refund_amount: refunding ? amount : '',
            idempotency_key: idempotencyKey,
        }));
        form.post(checkOut.url(key), {
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
            title={`Check out · ${unit.roomType}`}
            description={
                <>
                    {unit.primaryGuest ?? 'No primary guest'} ·{' '}
                    {unit.assigned.join(', ') || 'No room'} · departs{' '}
                    <DateText date={unit.departure} />
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel="Check out"
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
                    <div className="flex flex-col gap-1 text-sm">
                        <div className="flex justify-between gap-4">
                            <span className="text-muted-foreground">
                                Unit folio
                            </span>
                            <span>
                                {Number(preview.owing) > 0 ? (
                                    <span className="text-destructive">
                                        <MoneyText
                                            amount={preview.owing}
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
                    {Number(preview.owing) > 0 && preview.folioKey !== null && (
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
                    {!settled && Number(preview.owing) === 0 && (
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
