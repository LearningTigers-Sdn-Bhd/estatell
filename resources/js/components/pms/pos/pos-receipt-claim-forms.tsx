import { useForm } from '@inertiajs/react';
import { ID_TYPES, MALAYSIA_STATES } from '@/components/pms/e-invoice-fields';
import { EnumSelect } from '@/components/pms/enum-select';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { claim, confirm } from '@/routes/pos/receipt-claim';

export function PosReceiptClaimConfirmForm({ token }: { token: string }) {
    const form = useForm({ total: '', date: '' });

    return (
        <form
            className="flex flex-col gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(confirm.url({ token }), {
                    onFinish: () => form.reset('total'),
                });
            }}
        >
            <p className="text-sm text-muted-foreground">
                To get an e-invoice for this receipt, type the total and the
                date printed on it.
            </p>
            <Field data-invalid={!!form.errors.total}>
                <FieldLabel htmlFor="claim-total">
                    Total on the receipt
                </FieldLabel>
                <Input
                    id="claim-total"
                    inputMode="decimal"
                    className="tabular-nums"
                    autoComplete="off"
                    value={form.data.total}
                    onChange={(event) =>
                        form.setData('total', event.target.value)
                    }
                />
                <FieldError>{form.errors.total}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.date}>
                <FieldLabel htmlFor="claim-date">
                    Date on the receipt
                </FieldLabel>
                <Input
                    id="claim-date"
                    type="date"
                    value={form.data.date}
                    onChange={(event) =>
                        form.setData('date', event.target.value)
                    }
                />
                <FieldError>{form.errors.date}</FieldError>
            </Field>
            <Button
                type="submit"
                className="min-h-11"
                disabled={
                    form.processing ||
                    form.data.total === '' ||
                    form.data.date === ''
                }
                focusableWhenDisabled
            >
                {form.processing && <Spinner data-icon="inline-start" />}
                Continue
            </Button>
        </form>
    );
}

export function PosReceiptClaimDetailsForm({ token }: { token: string }) {
    const form = useForm({
        name: '',
        tin: '',
        id_type: 'NRIC',
        id_value: '',
        email: '',
        phone: '',
        address_line1: '',
        city: '',
        postal_code: '',
        state_code: '14',
        country_code: 'MYS',
    });

    const text = (
        name:
            | 'name'
            | 'tin'
            | 'id_value'
            | 'email'
            | 'phone'
            | 'address_line1'
            | 'city'
            | 'postal_code',
        label: string,
        hint?: string,
        placeholder?: string,
    ) => (
        <Field data-invalid={!!form.errors[name]}>
            <FieldLabel htmlFor={`claim-${name}`}>{label}</FieldLabel>
            {hint && <FieldDescription>{hint}</FieldDescription>}
            <Input
                id={`claim-${name}`}
                autoComplete="off"
                placeholder={placeholder}
                value={form.data[name]}
                onChange={(event) => form.setData(name, event.target.value)}
                aria-invalid={!!form.errors[name]}
            />
            <FieldError>{form.errors[name]}</FieldError>
        </Field>
    );

    const idPlaceholder =
        form.data.id_type === 'NRIC'
            ? '900101015555 (12 digits, no dashes)'
            : form.data.id_type === 'BRN'
              ? 'e.g. 202001012345'
              : 'Document number';

    return (
        <form
            className="flex flex-col gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(claim.url({ token }));
            }}
        >
            <p className="text-sm text-muted-foreground">
                Your details go to LHDN MyInvois on the e-invoice. We do not
                keep them in our logs.
            </p>
            {text('name', 'Name on the invoice')}
            {text(
                'tin',
                'Tax identification number (TIN)',
                'Starts with IG for an individual or C for a company.',
                'e.g. IG12345678901',
            )}
            <Field data-invalid={!!form.errors.id_type}>
                <FieldLabel htmlFor="claim-id-type">ID type</FieldLabel>
                <EnumSelect
                    id="claim-id-type"
                    name="id_type"
                    options={ID_TYPES}
                    value={form.data.id_type}
                    onValueChange={(value) => form.setData('id_type', value)}
                />
                <FieldError>{form.errors.id_type}</FieldError>
            </Field>
            {text('id_value', 'ID number', undefined, idPlaceholder)}
            {text('email', 'Email')}
            {text('phone', 'Phone')}
            {text('address_line1', 'Address')}
            {text('city', 'City')}
            {text('postal_code', 'Postcode')}
            <Field data-invalid={!!form.errors.state_code}>
                <FieldLabel htmlFor="claim-state">State</FieldLabel>
                <EnumSelect
                    id="claim-state"
                    name="state_code"
                    options={MALAYSIA_STATES}
                    value={form.data.state_code}
                    onValueChange={(value) => form.setData('state_code', value)}
                />
                <FieldError>{form.errors.state_code}</FieldError>
            </Field>
            <Button
                type="submit"
                className="min-h-11"
                disabled={form.processing}
                focusableWhenDisabled
            >
                {form.processing && <Spinner data-icon="inline-start" />}
                Send my e-invoice
            </Button>
        </form>
    );
}
