import { useForm, useHttp } from '@inertiajs/react';
import { AlertCircleIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    referenceCheck,
    store,
} from '@/routes/finance/city-ledger/companies/payments';

type Method = App.Data.CityLedger.ArPaymentMethodOptionData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companyId: number;
    methods: Method[];
    currency: App.Enums.Currency | null;
};

function RecordForm({
    open,
    onOpenChange,
    companyId,
    methods,
    currency,
}: Props) {
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

    const http = useHttp<
        { reference: string },
        App.Data.CityLedger.ArPaymentReferenceCheckData
    >('post', referenceCheck.url({ company: companyId }), { reference: '' });
    const [warning, setWarning] = useState<string | null>(null);
    const askReference = method?.requiresReference ? data.reference : '';

    useEffect(() => {
        if (askReference.trim() === '') {
            setWarning(null);

            return;
        }

        let current = true;
        const timer = window.setTimeout(() => {
            http.transform(() => ({ reference: askReference }));
            http.submit()
                .then((answer) => current && setWarning(answer.warning))
                .catch(() => current && setWarning(null));
        }, 300);

        return () => {
            current = false;
            window.clearTimeout(timer);
        };
        // The check depends only on the reference.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [askReference]);

    const submit = () => {
        form.transform((values) => ({
            ...values,
            reference: method?.requiresReference ? values.reference : '',
            idempotency_key: key,
        }));
        form.post(store.url({ company: companyId }), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Record payment"
            description="Money received from the company against its city ledger."
            errors={errors}
            onSubmit={submit}
            submitLabel="Record payment"
            processing={form.processing}
        >
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
                <FieldLabel htmlFor="ar-received_on">Received on</FieldLabel>
                <DatePicker
                    id="ar-received_on"
                    value={data.received_on}
                    onChange={(value) => form.setData('received_on', value)}
                    placeholder="Today's business date"
                    aria-invalid={!!errors.received_on}
                />
                <FieldDescription>
                    Leave empty for today's business date.
                </FieldDescription>
                <FieldError>{errors.received_on}</FieldError>
            </Field>
            {method?.requiresReference && (
                <Field data-invalid={!!errors.reference}>
                    <FieldLabel htmlFor="ar-reference">Reference</FieldLabel>
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
                    {warning && (
                        <Alert>
                            <AlertCircleIcon />
                            <AlertDescription>{warning}</AlertDescription>
                        </Alert>
                    )}
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
                    placeholder="e.g. Bank transfer for September invoices"
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
        </FormOverlay>
    );
}

/**
 * Records money a company paid against its city ledger. A repeated submit returns the same payment. Under the
 * reference, a warning shows when the same reference was already recorded on a folio payment or another AR payment of
 * the company; it never blocks the payment.
 */
export function RecordArPaymentDialog(props: Props) {
    return <RecordForm key={useFreshOnOpen(props.open)} {...props} />;
}
