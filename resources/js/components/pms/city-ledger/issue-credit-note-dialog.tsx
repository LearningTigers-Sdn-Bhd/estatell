import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/finance/city-ledger/invoices/credit-notes';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoice: App.Data.CityLedger.ArInvoiceData;
};

function CreditNoteForm({ open, onOpenChange, invoice }: Props) {
    const form = useForm({ amount: invoice.row.balance ?? '', reason: '' });
    const { data, errors } = form;

    const submit = () => {
        form.post(store.url({ invoice: invoice.row.id }), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Issue credit note"
            description={`Corrects invoice ${invoice.row.number}.`}
            errors={errors}
            onSubmit={submit}
            submitLabel="Issue credit note"
            processing={form.processing}
        >
            <Field data-invalid={!!errors.amount}>
                <FieldLabel htmlFor="credit-amount">Amount</FieldLabel>
                <Input
                    id="credit-amount"
                    inputMode="decimal"
                    value={data.amount}
                    onChange={(event) =>
                        form.setData('amount', event.target.value)
                    }
                    autoComplete="off"
                    autoFocus
                    aria-invalid={!!errors.amount}
                />
                <FieldDescription>
                    At most the balance of the invoice.
                </FieldDescription>
                <FieldError>{errors.amount}</FieldError>
            </Field>
            <Field data-invalid={!!errors.reason}>
                <FieldLabel htmlFor="credit-reason">Reason</FieldLabel>
                <Textarea
                    id="credit-reason"
                    value={data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    maxLength={500}
                    rows={3}
                    aria-invalid={!!errors.reason}
                />
                <FieldError>{errors.reason}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * Issues a credit note against an invoice. The note is applied to the invoice at once.
 */
export function IssueCreditNoteDialog(props: Props) {
    return <CreditNoteForm key={useFreshOnOpen(props.open)} {...props} />;
}
