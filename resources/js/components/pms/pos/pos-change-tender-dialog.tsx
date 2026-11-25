import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

type Order = App.Data.Pos.PosOrderData;

/**
 * Fix a tender keyed wrongly on a paid order: swap one payment for another tender of the same amount. Only works while the
 * shift and business date are still open; the server says why when it is not. A manager approves it.
 */
export function PosChangeTenderDialog({
    open,
    onClose,
    order,
    tenders,
    url,
}: {
    open: boolean;
    onClose: () => void;
    order: Order;
    tenders: EnumOption[];
    url: string;
}) {
    const payments = order.payments.filter(
        (payment) =>
            payment.direction === 'in' &&
            payment.status === 'captured' &&
            payment.tender !== 'Room charge',
    );
    const form = useForm({
        payment_id: String(payments[0]?.id ?? ''),
        tender_type: '',
        reference: '',
    });

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(value) => !value && onClose()}
            title="Change how this was paid"
            description="The old payment is kept and marked replaced. Only possible while the shift and business date are still open."
            errors={form.errors}
            processing={form.processing}
            submitLabel="Change tender"
            submitDisabled={
                form.data.payment_id === '' || form.data.tender_type === ''
            }
            onSubmit={() =>
                form.post(url, {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
        >
            <Field>
                <FieldLabel htmlFor="swap-payment">Payment</FieldLabel>
                <EnumSelect
                    id="swap-payment"
                    options={payments.map((payment) => ({
                        value: String(payment.id),
                        label: `${payment.tender} ${payment.amount}`,
                    }))}
                    value={form.data.payment_id}
                    onValueChange={(value) => form.setData('payment_id', value)}
                />
            </Field>
            <Field data-invalid={!!form.errors.tender_type}>
                <FieldLabel htmlFor="swap-tender">New tender</FieldLabel>
                <EnumSelect
                    id="swap-tender"
                    options={tenders}
                    value={form.data.tender_type}
                    onValueChange={(value) =>
                        form.setData('tender_type', value)
                    }
                    placeholder="Pick a tender"
                />
            </Field>
            <Field>
                <FieldLabel htmlFor="swap-reference">Reference</FieldLabel>
                <FieldDescription>
                    Card approval or wallet reference, if any.
                </FieldDescription>
                <Input
                    id="swap-reference"
                    maxLength={120}
                    value={form.data.reference}
                    onChange={(event) =>
                        form.setData('reference', event.target.value)
                    }
                />
            </Field>
        </FormOverlay>
    );
}
