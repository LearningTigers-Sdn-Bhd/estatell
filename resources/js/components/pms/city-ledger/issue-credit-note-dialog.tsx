import { useForm } from '@inertiajs/react';
import { FormError } from '@/components/pms/form-error';
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
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/finance/city-ledger/invoices/credit-notes';

function CreditNoteForm({
    invoice,
    onDone,
}: {
    invoice: App.Data.CityLedger.ArInvoiceData;
    onDone: () => void;
}) {
    const form = useForm({ amount: invoice.row.balance ?? '', reason: '' });
    const { data, errors } = form;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(store.url({ invoice: invoice.row.id }), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <FormError errors={errors} />
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
            </FieldGroup>
            <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>
                    Cancel
                </DialogClose>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Issue credit note
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * Issues a credit note against an invoice. The note is applied to the invoice at once.
 */
export function IssueCreditNoteDialog({
    open,
    onOpenChange,
    invoice,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoice: App.Data.CityLedger.ArInvoiceData;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Issue credit note</DialogTitle>
                    <DialogDescription>
                        Corrects invoice {invoice.row.number}.
                    </DialogDescription>
                </DialogHeader>
                <CreditNoteForm
                    invoice={invoice}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
