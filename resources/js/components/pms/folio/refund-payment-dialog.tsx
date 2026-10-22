import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { RefundMethodFields } from '@/components/pms/folio/refund-method-fields';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { useRetainedValue } from '@/components/pms/use-retained-value';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store } from '@/routes/reservations/folios/payments/refunds';

type Row = App.Data.Folio.FolioRowData;

type FormProps = {
    open: boolean;
    onClose: () => void;
    reservationId: number;
    folioId: number;
    row: Row;
    currency: App.Data.Property.CurrencyData | null;
    canOtherMethod: boolean;
    options?: App.Data.Payments.PaymentFormOptionsData;
};

function RefundForm({
    open,
    onClose,
    reservationId,
    folioId,
    row,
    currency,
    canOtherMethod,
    options,
}: FormProps) {
    useFormOptions(
        open && canOtherMethod,
        options !== undefined,
        'paymentOptions',
    );

    const [key] = useState(() => crypto.randomUUID());
    const form = useForm({
        amount: row.refundable ?? '',
        reference: '',
        payment_method_id: '',
        reason: '',
    });
    const { data, errors } = form;

    const submit = () => {
        form.transform((values) => ({
            ...values,
            reason: values.payment_method_id === '' ? '' : values.reason,
            idempotency_key: key,
        }));
        form.post(
            store.url({
                reservation: reservationId,
                folio: folioId,
                payment: row.paymentId ?? 0,
            }),
            {
                preserveScroll: true,
                except: KEEP_OPTIONS,
                onSuccess: onClose,
            },
        );
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title={`Refund ${row.description}`}
            description="The refund posts on this folio. A surcharge stays: reverse it on its own if needed."
            errors={errors}
            onSubmit={submit}
            submitLabel="Refund"
            processing={form.processing}
            submitDisabled={data.amount === ''}
        >
            <Field data-invalid={!!errors.amount}>
                <FieldLabel htmlFor="refund-amount">Amount</FieldLabel>
                <MoneyInput
                    id="refund-amount"
                    value={data.amount}
                    onChange={(value) => form.setData('amount', value)}
                    currency={currency}
                    invalid={!!errors.amount}
                />
                {row.refundable !== null && (
                    <FieldDescription>
                        Up to{' '}
                        <MoneyText
                            amount={row.refundable}
                            currency={currency}
                        />{' '}
                        can be refunded.
                    </FieldDescription>
                )}
                <FieldError>{errors.amount}</FieldError>
            </Field>
            {canOtherMethod && (
                <RefundMethodFields
                    idPrefix="refund"
                    methods={options?.methods ?? []}
                    sourceIsCash={row.methodType === 'cash'}
                    methodId={data.payment_method_id}
                    reason={data.reason}
                    onMethodId={(value) =>
                        form.setData('payment_method_id', value)
                    }
                    onReason={(value) => form.setData('reason', value)}
                    errors={errors}
                />
            )}
            <Field data-invalid={!!errors.reference}>
                <FieldLabel htmlFor="refund-reference">
                    Reference (optional)
                </FieldLabel>
                <Input
                    id="refund-reference"
                    maxLength={100}
                    placeholder="e.g. RF-1024"
                    value={data.reference}
                    onChange={(event) =>
                        form.setData('reference', event.target.value)
                    }
                    aria-invalid={!!errors.reference}
                />
                <FieldError>{errors.reference}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * Gives money back from a payment. The amount defaults to what is left to refund. The money goes back to the payment's
 * own method; a user with `payment.refund-other-method` can pick another one and must give a reason. The method surcharge is not
 * refunded: reverse that charge on its own when the fee must go too.
 */
export function RefundPaymentDialog({
    reservationId,
    folioId,
    row,
    currency,
    canOtherMethod,
    options,
    onClose,
}: {
    reservationId: number;
    folioId: number;
    row: Row | null;
    currency: App.Data.Property.CurrencyData | null;
    canOtherMethod: boolean;
    options?: App.Data.Payments.PaymentFormOptionsData;
    onClose: () => void;
}) {
    const open = row !== null;
    const kept = useRetainedValue(row);
    const fresh = useFreshOnOpen(open);

    if (kept === null) {
        return null;
    }

    return (
        <RefundForm
            key={`${kept.lineId}-${fresh}`}
            open={open}
            onClose={onClose}
            reservationId={reservationId}
            folioId={folioId}
            row={kept}
            currency={currency}
            canOtherMethod={canOtherMethod}
            options={options}
        />
    );
}
