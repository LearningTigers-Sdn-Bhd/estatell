import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { useRetainedValue } from '@/components/pms/use-retained-value';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { reverse } from '@/routes/finance/city-ledger/companies/payments/allocations';

type Allocation = App.Data.CityLedger.ArAllocationRowData;

type FormProps = {
    open: boolean;
    onClose: () => void;
    allocation: Allocation;
    currency: App.Enums.Currency | null;
};

function ReverseForm({ open, onClose, allocation, currency }: FormProps) {
    const form = useForm({ reason: '' });
    const { data, errors } = form;

    const submit = () => {
        form.post(
            reverse.url({
                company: allocation.companyId,
                payment: allocation.paymentId,
                allocation: allocation.id,
            }),
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title="Reverse allocation"
            description={
                <>
                    {allocation.receiptNumber} ·{' '}
                    <MoneyText amount={allocation.amount} currency={currency} />
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel="Reverse allocation"
            submitVariant="destructive"
            processing={form.processing}
        >
            <Field data-invalid={!!errors.reason}>
                <FieldLabel htmlFor="reverse-reason">Reason</FieldLabel>
                <Textarea
                    id="reverse-reason"
                    value={data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    maxLength={500}
                    rows={3}
                    autoFocus
                    aria-invalid={!!errors.reason}
                />
                <FieldError>{errors.reason}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * Reverses an allocation. The money goes back to the payment as credit and the invoice owes it again.
 */
export function ReverseAllocationDialog({
    allocation,
    onClose,
    currency,
}: {
    allocation: Allocation | null;
    onClose: () => void;
    currency: App.Enums.Currency | null;
}) {
    const open = allocation !== null;
    const kept = useRetainedValue(allocation);
    const fresh = useFreshOnOpen(open);

    if (kept === null) {
        return null;
    }

    return (
        <ReverseForm
            key={`${kept.id}-${fresh}`}
            open={open}
            onClose={onClose}
            allocation={kept}
            currency={currency}
        />
    );
}
