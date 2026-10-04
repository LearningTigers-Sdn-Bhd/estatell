import { useForm } from '@inertiajs/react';
import { FormError } from '@/components/pms/reservations/form-error';
import { MoneyText } from '@/components/pms/money-text';
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
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { reverse } from '@/routes/finance/city-ledger/companies/payments/allocations';

type Allocation = App.Data.CityLedger.ArAllocationRowData;

function ReverseForm({
    allocation,
    onDone,
}: {
    allocation: Allocation;
    onDone: () => void;
}) {
    const form = useForm({ reason: '' });
    const { data, errors } = form;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(
            reverse.url({
                company: allocation.companyId,
                payment: allocation.paymentId,
                allocation: allocation.id,
            }),
            { preserveScroll: true, onSuccess: onDone },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <FormError errors={errors} />
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
            </FieldGroup>
            <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>
                    Cancel
                </DialogClose>
                <Button
                    type="submit"
                    variant="destructive"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Reverse allocation
                </Button>
            </DialogFooter>
        </form>
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
    return (
        <Dialog
            open={allocation !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Reverse allocation</DialogTitle>
                    <DialogDescription>
                        {allocation?.receiptNumber} ·{' '}
                        <MoneyText
                            amount={allocation?.amount ?? '0.00'}
                            currency={currency}
                        />
                    </DialogDescription>
                </DialogHeader>
                {allocation && (
                    <ReverseForm
                        key={allocation.id}
                        allocation={allocation}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
