import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { FormError } from '@/components/pms/form-error';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/reservations/folios/payments/refunds';

type Row = App.Data.Folio.FolioRowData;

function RefundForm({
    reservationId,
    folioId,
    row,
    currency,
    onDone,
}: {
    reservationId: number;
    folioId: number;
    row: Row;
    currency: App.Enums.Currency | null;
    onDone: () => void;
}) {
    const [key] = useState(() => crypto.randomUUID());
    const form = useForm({ amount: row.refundable ?? '', reference: '' });
    const { data, errors } = form;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({ ...values, idempotency_key: key }));
        form.post(
            store.url({
                reservation: reservationId,
                folio: folioId,
                payment: row.paymentId ?? 0,
            }),
            {
                preserveScroll: true,
                except: KEEP_OPTIONS,
                onSuccess: onDone,
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <FormError errors={errors} />
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
                <Field data-invalid={!!errors.reference}>
                    <FieldLabel htmlFor="refund-reference">
                        Reference (optional)
                    </FieldLabel>
                    <Input
                        id="refund-reference"
                        maxLength={100}
                        value={data.reference}
                        onChange={(event) =>
                            form.setData('reference', event.target.value)
                        }
                        aria-invalid={!!errors.reference}
                    />
                    <FieldError>{errors.reference}</FieldError>
                </Field>
            </FieldGroup>
            <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>
                    Cancel
                </DialogClose>
                <Button
                    type="submit"
                    disabled={form.processing || data.amount === ''}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Refund
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * Gives money back from a payment. The amount defaults to what is left to refund. The method surcharge is not
 * refunded: reverse that charge on its own when the fee must go too.
 */
export function RefundPaymentDialog({
    reservationId,
    folioId,
    row,
    currency,
    onClose,
}: {
    reservationId: number;
    folioId: number;
    row: Row | null;
    currency: App.Enums.Currency | null;
    onClose: () => void;
}) {
    return (
        <Dialog open={row !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Refund {row?.description}</DialogTitle>
                    <DialogDescription>
                        The refund posts on this folio with the same method. A
                        surcharge stays: reverse it on its own if needed.
                    </DialogDescription>
                </DialogHeader>
                {row !== null && (
                    <RefundForm
                        reservationId={reservationId}
                        folioId={folioId}
                        row={row}
                        currency={currency}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
