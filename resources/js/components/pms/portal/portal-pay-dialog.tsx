import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    BankAccountCard,
    BankAccountList,
    CopyRow,
} from '@/components/pms/portal/bank-account-list';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    /** The proof or slip store URL. The server decides where the money goes. */
    action: string;
    /** "Left to pay" on a booking, "Balance" on Statements, with its amount. */
    figureLabel: string;
    figure: string;
    /** The payment reference to use, with a copy button. Statements has none. */
    reference?: string;
    /** A line under the reference, for example how a payment from Statements is used. */
    note?: ReactNode;
    /** Filled in as Amount paid; empty on Statements. */
    defaultAmount?: string;
    bankAccounts: App.Data.Catalogues.BankAccountData[];
    /** Shown when the property has no available bank account. */
    contact?: string;
    currency: App.Data.Property.CurrencyData | null;
};

function PayForm({
    open,
    onOpenChange,
    title,
    action,
    figureLabel,
    figure,
    reference,
    note,
    defaultAmount = '',
    bankAccounts,
    contact,
    currency,
}: Props) {
    const form = useForm<{
        file: File | null;
        bank_account_id: string;
        amount: string;
        reference: string;
    }>({
        file: null,
        bank_account_id: '',
        amount: defaultAmount,
        reference: '',
    });
    const { data, errors } = form;

    const submit = () => {
        form.post(action, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            size="lg"
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            description="Pay by bank transfer, then send the slip. The property checks it and records your payment."
            errors={errors}
            onSubmit={submit}
            submitLabel="Send slip"
            processing={form.processing}
            submitDisabled={data.file === null}
        >
            <div className="grid gap-6 lg:grid-cols-2">
                <div className="flex flex-col gap-4 text-sm">
                    <div className="flex flex-col gap-0.5">
                        <span className="text-muted-foreground">
                            {figureLabel}
                        </span>
                        <span className="font-heading text-xl font-semibold tabular-nums">
                            <MoneyText amount={figure} currency={currency} />
                        </span>
                    </div>

                    {bankAccounts.length > 1 ? (
                        <FieldSet data-invalid={!!errors.bank_account_id}>
                            <FieldLegend variant="label">Paid into</FieldLegend>
                            <RadioGroup
                                name="bank_account_id"
                                value={data.bank_account_id}
                                onValueChange={(value) =>
                                    form.setData(
                                        'bank_account_id',
                                        String(value),
                                    )
                                }
                                className="-m-1 grid max-h-72 gap-2 overflow-y-auto p-1"
                            >
                                {bankAccounts.map((account) => (
                                    <BankAccountCard
                                        key={account.id}
                                        account={account}
                                        radioId={`pay-account-${account.id}`}
                                        radio={
                                            <RadioGroupItem
                                                id={`pay-account-${account.id}`}
                                                value={String(account.id)}
                                                aria-invalid={
                                                    !!errors.bank_account_id
                                                }
                                                className="mt-0.5"
                                            />
                                        }
                                    />
                                ))}
                            </RadioGroup>
                            <FieldError>{errors.bank_account_id}</FieldError>
                        </FieldSet>
                    ) : bankAccounts.length === 1 ? (
                        <div className="flex flex-col gap-2">
                            <span className="font-medium">Transfer to</span>
                            <BankAccountList bankAccounts={bankAccounts} />
                        </div>
                    ) : (
                        <p className="text-muted-foreground">
                            Contact the property for its bank details
                            {contact ? `: ${contact}` : ''}.
                        </p>
                    )}

                    {reference && (
                        <div className="flex flex-col rounded-lg border px-3 py-1 text-sm">
                            <CopyRow
                                label="Payment reference"
                                value={reference}
                            />
                        </div>
                    )}

                    {note && <p className="text-muted-foreground">{note}</p>}
                </div>

                <div className="flex flex-col gap-4">
                    <Field data-invalid={!!errors.file}>
                        <FieldLabel htmlFor="pay-file">
                            Payment proof
                        </FieldLabel>
                        <Input
                            id="pay-file"
                            type="file"
                            className="py-0 file:h-7.5 file:items-center"
                            accept="image/jpeg,image/png,image/webp,application/pdf"
                            onChange={(event) =>
                                form.setData(
                                    'file',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                            aria-invalid={!!errors.file}
                        />
                        <FieldDescription>
                            Image or PDF, up to 10 MB.
                        </FieldDescription>
                        <FieldError>{errors.file}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.amount}>
                        <FieldLabel htmlFor="pay-amount">
                            Amount paid
                        </FieldLabel>
                        <MoneyInput
                            id="pay-amount"
                            value={data.amount}
                            onChange={(value) => form.setData('amount', value)}
                            currency={currency}
                            invalid={!!errors.amount}
                        />
                        <FieldError>{errors.amount}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.reference}>
                        <FieldLabel htmlFor="pay-reference">
                            Bank reference
                        </FieldLabel>
                        <Input
                            id="pay-reference"
                            maxLength={100}
                            placeholder="e.g. MBB-77812"
                            value={data.reference}
                            onChange={(event) =>
                                form.setData('reference', event.target.value)
                            }
                            aria-invalid={!!errors.reference}
                        />
                        <FieldError>{errors.reference}</FieldError>
                    </Field>
                </div>
            </div>
        </FormOverlay>
    );
}

/**
 * Pay by bank transfer and send the slip, from a portal booking or from Statements. Two columns from 1024 px: where to
 * pay on the left (the figure, the bank accounts, the reference), the slip on the right. With 2 or more accounts the
 * picked one is Paid into. The server decides where the money goes and every figure. See
 * docs/15-city-ledger/06-agent-payment-schedule.md#payment-proof.
 */
export function PortalPayDialog(props: Props) {
    return <PayForm key={useFreshOnOpen(props.open)} {...props} />;
}
