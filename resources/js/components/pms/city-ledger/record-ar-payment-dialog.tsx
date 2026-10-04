import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyInput } from '@/components/pms/money-input';
import { FormError } from '@/components/pms/reservations/form-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/finance/city-ledger/companies/payments';

type Method = App.Data.CityLedger.ArPaymentMethodOptionData;

function RecordForm({
    companyId,
    methods,
    currency,
    onDone,
}: {
    companyId: number;
    methods: Method[];
    currency: App.Enums.Currency | null;
    onDone: () => void;
}) {
    const [key] = useState(() => crypto.randomUUID());
    const form = useForm({
        payment_method_id: methods[0] ? String(methods[0].id) : '',
        amount: '',
        received_on: '',
        reference: '',
        notes: '',
        allocate: true,
    });
    const { data, errors } = form;
    const method = methods.find(
        (one) => String(one.id) === data.payment_method_id,
    );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            reference: method?.requiresReference ? values.reference : '',
            idempotency_key: key,
        }));
        form.post(store.url({ company: companyId }), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <FormError errors={errors} />
                <Field data-invalid={!!errors.payment_method_id}>
                    <FieldLabel htmlFor="ar-method">Method</FieldLabel>
                    <EnumSelect
                        id="ar-method"
                        options={methods.map((one) => ({
                            value: String(one.id),
                            label: one.name,
                        }))}
                        value={data.payment_method_id}
                        onValueChange={(value) =>
                            form.setData('payment_method_id', value)
                        }
                        aria-invalid={!!errors.payment_method_id}
                    />
                    <FieldError>{errors.payment_method_id}</FieldError>
                </Field>
                <Field data-invalid={!!errors.amount}>
                    <FieldLabel htmlFor="ar-amount">Amount</FieldLabel>
                    <MoneyInput
                        id="ar-amount"
                        value={data.amount}
                        onChange={(value) => form.setData('amount', value)}
                        currency={currency}
                        invalid={!!errors.amount}
                    />
                    <FieldError>{errors.amount}</FieldError>
                </Field>
                <Field data-invalid={!!errors.received_on}>
                    <FieldLabel htmlFor="ar-received_on">
                        Received on
                    </FieldLabel>
                    <Input
                        id="ar-received_on"
                        type="date"
                        value={data.received_on}
                        onChange={(event) =>
                            form.setData('received_on', event.target.value)
                        }
                        aria-invalid={!!errors.received_on}
                    />
                    <FieldDescription>
                        Leave empty for today's business date.
                    </FieldDescription>
                    <FieldError>{errors.received_on}</FieldError>
                </Field>
                {method?.requiresReference && (
                    <Field data-invalid={!!errors.reference}>
                        <FieldLabel htmlFor="ar-reference">
                            Reference
                        </FieldLabel>
                        <Input
                            id="ar-reference"
                            value={data.reference}
                            onChange={(event) =>
                                form.setData('reference', event.target.value)
                            }
                            maxLength={100}
                            autoComplete="off"
                            aria-invalid={!!errors.reference}
                        />
                        <FieldError>{errors.reference}</FieldError>
                    </Field>
                )}
                <Field data-invalid={!!errors.notes}>
                    <FieldLabel htmlFor="ar-notes">Notes</FieldLabel>
                    <Textarea
                        id="ar-notes"
                        value={data.notes}
                        onChange={(event) =>
                            form.setData('notes', event.target.value)
                        }
                        maxLength={500}
                        rows={2}
                    />
                    <FieldError>{errors.notes}</FieldError>
                </Field>
                <FieldLabel htmlFor="ar-allocate">
                    <Field orientation="horizontal">
                        <Checkbox
                            id="ar-allocate"
                            checked={data.allocate}
                            onCheckedChange={(checked) =>
                                form.setData('allocate', checked)
                            }
                        />
                        <FieldContent>
                            <span className="text-sm font-medium">
                                Allocate to oldest invoices
                            </span>
                            <FieldDescription>
                                What is left stays as credit on the company.
                            </FieldDescription>
                        </FieldContent>
                    </Field>
                </FieldLabel>
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
                    Record payment
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * Records money a company paid against its city ledger. A repeated submit returns the same payment.
 */
export function RecordArPaymentDialog({
    open,
    onOpenChange,
    companyId,
    methods,
    currency,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companyId: number;
    methods: Method[];
    currency: App.Enums.Currency | null;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Record payment</DialogTitle>
                    <DialogDescription>
                        Money received from the company against its city ledger.
                    </DialogDescription>
                </DialogHeader>
                <RecordForm
                    companyId={companyId}
                    methods={methods}
                    currency={currency}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
