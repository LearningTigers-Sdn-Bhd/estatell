import { useForm } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useState } from 'react';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

/**
 * The proofs an agent sent: open the file, its amount and reference, and its status with the reason if rejected.
 */
export function PaymentProofList({
    proofs,
    currency,
}: {
    proofs: App.Data.Payments.PaymentProofData[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    if (proofs.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-col gap-2 text-sm">
            {proofs.map((proof) => (
                <li
                    key={proof.id}
                    className="flex flex-wrap items-center justify-between gap-2"
                >
                    <a
                        href={proof.fileUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1.5 underline-offset-4 hover:underline"
                    >
                        <FileText className="size-4" aria-hidden />
                        <MoneyText amount={proof.amount} currency={currency} />
                        {proof.reference && (
                            <span className="text-muted-foreground">
                                · {proof.reference}
                            </span>
                        )}
                    </a>
                    <span className="flex items-center gap-2">
                        {proof.rejectReason && (
                            <span className="text-muted-foreground">
                                {proof.rejectReason}
                            </span>
                        )}
                        <StatusBadge status={proof.badge} />
                    </span>
                </li>
            ))}
        </ul>
    );
}

/**
 * Send a proof of payment: the file (image or PDF), the amount and the bank reference. The form starts empty again
 * after a save, and the server's message says whether it was sent.
 */
export function PaymentProofForm({
    action,
    idPrefix = 'proof',
    submitLabel = 'Send proof',
}: {
    action: string;
    idPrefix?: string;
    submitLabel?: string;
}) {
    const [fileKey, setFileKey] = useState(0);
    const form = useForm<{
        file: File | null;
        amount: string;
        reference: string;
    }>({
        file: null,
        amount: '',
        reference: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(action, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setFileKey((key) => key + 1);
            },
        });
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-3 border-t pt-3 sm:grid-cols-3"
        >
            <Field data-invalid={!!form.errors.file}>
                <FieldLabel htmlFor={`${idPrefix}-file`}>
                    Payment proof
                </FieldLabel>
                <Input
                    id={`${idPrefix}-file`}
                    key={fileKey}
                    type="file"
                    accept="image/jpeg,image/png,image/webp,application/pdf"
                    onChange={(event) =>
                        form.setData('file', event.target.files?.[0] ?? null)
                    }
                    aria-invalid={!!form.errors.file}
                />
                <FieldDescription>Image or PDF, up to 10 MB.</FieldDescription>
                <FieldError>{form.errors.file}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.amount}>
                <FieldLabel htmlFor={`${idPrefix}-amount`}>
                    Amount paid
                </FieldLabel>
                <Input
                    id={`${idPrefix}-amount`}
                    inputMode="decimal"
                    value={form.data.amount}
                    onChange={(event) =>
                        form.setData('amount', event.target.value)
                    }
                    aria-invalid={!!form.errors.amount}
                />
                <FieldError>{form.errors.amount}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.reference}>
                <FieldLabel htmlFor={`${idPrefix}-reference`}>
                    Bank reference
                </FieldLabel>
                <Input
                    id={`${idPrefix}-reference`}
                    maxLength={100}
                    value={form.data.reference}
                    onChange={(event) =>
                        form.setData('reference', event.target.value)
                    }
                    aria-invalid={!!form.errors.reference}
                />
                <FieldError>{form.errors.reference}</FieldError>
            </Field>
            <div className="sm:col-span-3">
                <Button
                    type="submit"
                    disabled={form.processing || form.data.file === null}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
