import { useForm } from '@inertiajs/react';
import { AlertCircleIcon } from 'lucide-react';
import { useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import { store as storeCredit } from '@/routes/finance/city-ledger/companies/old-system/credits';
import { store as storeInvoice } from '@/routes/finance/city-ledger/companies/old-system/invoices';

type Kind = 'invoice' | 'credit';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companyId: number;
    companyName: string;
    currency: App.Data.Property.CurrencyData | null;
    /** The credit headroom of the company (limit minus exposure), or null with no limit. */
    headroom: string | null;
};

function OldSystemForm({
    open,
    onOpenChange,
    companyId,
    companyName,
    currency,
    headroom,
}: Props) {
    const [key] = useState(() => crypto.randomUUID());
    const [kind, setKind] = useState<Kind>('invoice');
    const form = useForm({
        number: '',
        issued_on: '',
        due_on: '',
        reference: '',
        received_on: '',
        amount: '',
        reason: '',
    });
    const { data, errors } = form;
    const decimals = currency?.decimals ?? 2;
    const overBy =
        kind === 'invoice' && headroom !== null
            ? Number(data.amount || 0) - Number(headroom)
            : 0;

    const submit = () => {
        form.transform((values) =>
            kind === 'invoice'
                ? {
                      number: values.number,
                      issued_on: values.issued_on,
                      due_on: values.due_on,
                      amount: values.amount,
                      reason: values.reason,
                  }
                : {
                      reference: values.reference,
                      received_on: values.received_on,
                      amount: values.amount,
                      reason: values.reason,
                      idempotency_key: key,
                  },
        );
        const target = kind === 'invoice' ? storeInvoice : storeCredit;
        form.post(target.url({ company: companyId }), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={`Add from old system · ${companyName}`}
            description="Bring a balance from the old PMS into the city ledger."
            errors={errors}
            onSubmit={submit}
            submitLabel="Save"
            processing={form.processing}
        >
            <RadioGroup
                value={kind}
                onValueChange={(value) => setKind(value as Kind)}
                className="flex gap-6"
            >
                <label className="flex items-center gap-2 text-sm">
                    <RadioGroupItem value="invoice" />
                    Unpaid invoice
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <RadioGroupItem value="credit" />
                    Credit on account
                </label>
            </RadioGroup>

            {kind === 'invoice' ? (
                <>
                    <Field data-invalid={!!errors.number}>
                        <FieldLabel htmlFor="old-number">
                            Old invoice no.
                        </FieldLabel>
                        <Input
                            id="old-number"
                            value={data.number}
                            onChange={(event) =>
                                form.setData('number', event.target.value)
                            }
                            placeholder="e.g. INV-2026-0912"
                            maxLength={50}
                            autoComplete="off"
                            aria-invalid={!!errors.number}
                        />
                        <FieldError>{errors.number}</FieldError>
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field data-invalid={!!errors.issued_on}>
                            <FieldLabel htmlFor="old-issued">
                                Issued on
                            </FieldLabel>
                            <DatePicker
                                id="old-issued"
                                value={data.issued_on}
                                onChange={(value) =>
                                    form.setData('issued_on', value)
                                }
                                placeholder="e.g. 12 Sep 2026"
                                aria-invalid={!!errors.issued_on}
                            />
                            <FieldError>{errors.issued_on}</FieldError>
                        </Field>
                        <Field data-invalid={!!errors.due_on}>
                            <FieldLabel htmlFor="old-due">Due on</FieldLabel>
                            <DatePicker
                                id="old-due"
                                value={data.due_on}
                                onChange={(value) =>
                                    form.setData('due_on', value)
                                }
                                placeholder="e.g. 12 Oct 2026"
                                aria-invalid={!!errors.due_on}
                            />
                            <FieldError>{errors.due_on}</FieldError>
                        </Field>
                    </div>
                </>
            ) : (
                <>
                    <Field data-invalid={!!errors.reference}>
                        <FieldLabel htmlFor="old-reference">
                            Old receipt no.
                        </FieldLabel>
                        <Input
                            id="old-reference"
                            value={data.reference}
                            onChange={(event) =>
                                form.setData('reference', event.target.value)
                            }
                            placeholder="e.g. OR-2026-0345"
                            maxLength={100}
                            autoComplete="off"
                            aria-invalid={!!errors.reference}
                        />
                        <FieldError>{errors.reference}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.received_on}>
                        <FieldLabel htmlFor="old-received">
                            Received on
                        </FieldLabel>
                        <DatePicker
                            id="old-received"
                            value={data.received_on}
                            onChange={(value) =>
                                form.setData('received_on', value)
                            }
                            placeholder="e.g. 5 Sep 2026"
                            aria-invalid={!!errors.received_on}
                        />
                        <FieldError>{errors.received_on}</FieldError>
                    </Field>
                </>
            )}

            <Field data-invalid={!!errors.amount}>
                <FieldLabel htmlFor="old-amount">
                    {kind === 'invoice' ? 'Amount owed' : 'Amount'}
                </FieldLabel>
                <MoneyInput
                    id="old-amount"
                    value={data.amount}
                    onChange={(value) => form.setData('amount', value)}
                    currency={currency}
                    invalid={!!errors.amount}
                />
                <FieldDescription>
                    {kind === 'invoice'
                        ? 'What is still unpaid. For a part-paid invoice, enter the rest and put the original total in the reason.'
                        : 'Money the company paid in the old PMS and has not used yet. Do not also record it on a folio.'}
                </FieldDescription>
                <FieldError>{errors.amount}</FieldError>
            </Field>

            <Field data-invalid={!!errors.reason}>
                <FieldLabel htmlFor="old-reason">Reason</FieldLabel>
                <Textarea
                    id="old-reason"
                    value={data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    placeholder="e.g. Migrated from old PMS"
                    maxLength={500}
                    rows={2}
                />
                <FieldError>{errors.reason}</FieldError>
            </Field>

            {overBy > 0 && (
                <Alert>
                    <AlertCircleIcon />
                    <AlertDescription>
                        {companyName} will be over its credit limit by{' '}
                        <MoneyText
                            amount={overBy.toFixed(decimals)}
                            currency={currency}
                        />
                        .
                    </AlertDescription>
                </Alert>
            )}
        </FormOverlay>
    );
}

/**
 * Adds an unpaid invoice or credit on account from the old PMS to a company, inside the migration window. The credit
 * limit warning is worked out from the company's headroom and never blocks saving.
 */
export function AddOldSystemBalanceDialog(props: Props) {
    return <OldSystemForm key={useFreshOnOpen(props.open)} {...props} />;
}
