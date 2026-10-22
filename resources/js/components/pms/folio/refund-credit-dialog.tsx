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
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { store } from '@/routes/reservations/folios/refund-credit';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    folio: App.Data.Folio.FolioData;
    currency: App.Data.Property.CurrencyData | null;
    options?: App.Data.Payments.PaymentFormOptionsData;
};

function RefundCreditForm({
    open,
    onOpenChange,
    reservationId,
    folio,
    currency,
    options,
}: Props) {
    const canOtherMethod = folio.canRefundOtherMethod;

    useFormOptions(
        open && canOtherMethod,
        options !== undefined,
        'paymentOptions',
    );

    const [key] = useState(() => crypto.randomUUID());
    const form = useForm({
        amount: folio.refundCredit ?? '',
        payment_method_id: '',
        reason: '',
    });
    const { data, errors } = form;
    const sourceIsCash = folio.rows
        .filter((row) => row.actions.includes('refund'))
        .every((row) => row.methodType === 'cash');

    const submit = () => {
        form.transform((values) => ({
            ...values,
            reason: values.payment_method_id === '' ? '' : values.reason,
            idempotency_key: key,
        }));
        form.post(
            store.url({ reservation: reservationId, folio: Number(folio.key) }),
            {
                preserveScroll: true,
                except: KEEP_OPTIONS,
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Refund credit"
            description={`Give money back from the payments on ${folio.name}: the newest payment first, security deposits last.`}
            errors={errors}
            onSubmit={submit}
            submitLabel="Refund"
            processing={form.processing}
            submitDisabled={data.amount === ''}
        >
            <Field data-invalid={!!errors.amount}>
                <FieldLabel htmlFor="refund-credit-amount">Amount</FieldLabel>
                <MoneyInput
                    id="refund-credit-amount"
                    value={data.amount}
                    onChange={(value) => form.setData('amount', value)}
                    currency={currency}
                    invalid={!!errors.amount}
                />
                {folio.refundCredit !== null && (
                    <FieldDescription>
                        Up to{' '}
                        <MoneyText
                            amount={folio.refundCredit}
                            currency={currency}
                        />{' '}
                        can be refunded.
                    </FieldDescription>
                )}
                <FieldError>{errors.amount}</FieldError>
            </Field>
            {canOtherMethod && (
                <RefundMethodFields
                    idPrefix="refund-credit"
                    methods={options?.methods ?? []}
                    sourceIsCash={sourceIsCash}
                    methodId={data.payment_method_id}
                    reason={data.reason}
                    onMethodId={(value) =>
                        form.setData('payment_method_id', value)
                    }
                    onReason={(value) => form.setData('reason', value)}
                    errors={errors}
                />
            )}
        </FormOverlay>
    );
}

/**
 * Gives back the credit of an Open folio without a check-out. The amount defaults to the most that can go back. Each
 * payment is refunded to its own method, or every part goes to the method a user with `payment.refund-other-method`
 * picks, with a reason.
 */
export function RefundCreditDialog(props: Props) {
    return <RefundCreditForm key={useFreshOnOpen(props.open)} {...props} />;
}
