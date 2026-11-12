import { useForm } from '@inertiajs/react';
import { FilePlus2, Undo2 } from 'lucide-react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { creditNote } from '@/routes/finance/e-invoices';
import { type Submission } from '@/components/pms/einvoice/submission';

export function CreditNoteForm({
    open,
    onOpenChange,
    submission,
    currency,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    submission: Submission;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const form = useForm({
        kind: 'credit_note' as 'credit_note' | 'refund_note',
        amount: '',
        reason: '',
        credit_note_number: `CN-${submission.number}-${submission.credit_notes.length + 1}`,
    });
    const { data, errors } = form;
    const isRefund = data.kind === 'refund_note';

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={isRefund ? 'Issue refund note' : 'Issue credit note'}
            description={`Lowers what ${submission.number} charged. The e-invoice stays filed and LHDN records the note against it.`}
            errors={errors}
            onSubmit={() =>
                form.post(creditNote.url({ submission: submission.id }), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                })
            }
            submitLabel={isRefund ? 'Issue refund note' : 'Issue credit note'}
            processing={form.processing}
        >
            <ChoiceCards
                id="adjustment-kind"
                legend="Was money paid back to the guest?"
                value={data.kind}
                onValueChange={(kind) => {
                    const next = kind as 'credit_note' | 'refund_note';
                    form.setData({
                        ...data,
                        kind: next,
                        credit_note_number: `${next === 'refund_note' ? 'RN' : 'CN'}-${submission.number}-${submission.credit_notes.length + 1}`,
                    });
                }}
                choices={[
                    {
                        value: 'credit_note',
                        label: 'No, the amount is lowered',
                        description:
                            'A credit note. For example a fee waived before the guest paid it.',
                        icon: FilePlus2,
                    },
                    {
                        value: 'refund_note',
                        label: 'Yes, money was paid back',
                        description:
                            'A refund note. Not needed for an overpayment, a payment made by mistake or a returned deposit.',
                        icon: Undo2,
                    },
                ]}
            />
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
                {submission.amounts.total && (
                    <FieldDescription>
                        The e-invoice is for{' '}
                        <MoneyText
                            amount={submission.amounts.total}
                            currency={currency}
                        />
                        .
                    </FieldDescription>
                )}
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
                    rows={2}
                    placeholder="For example: late checkout fee waived"
                    aria-invalid={!!errors.reason}
                />
                <FieldError>{errors.reason}</FieldError>
            </Field>
            <Field data-invalid={!!errors.credit_note_number}>
                <FieldLabel htmlFor="credit-number">
                    {isRefund ? 'Refund note number' : 'Credit note number'}
                </FieldLabel>
                <Input
                    id="credit-number"
                    value={data.credit_note_number}
                    onChange={(event) =>
                        form.setData('credit_note_number', event.target.value)
                    }
                    maxLength={50}
                    aria-invalid={!!errors.credit_note_number}
                />
                <FieldError>{errors.credit_note_number}</FieldError>
            </Field>
        </FormOverlay>
    );
}

export function CreditNoteDialog(props: Parameters<typeof CreditNoteForm>[0]) {
    return <CreditNoteForm key={useFreshOnOpen(props.open)} {...props} />;
}
