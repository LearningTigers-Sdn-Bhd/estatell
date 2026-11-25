import { Head, useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import {
    ID_TYPES,
    MALAYSIA_STATES,
} from '@/components/pms/folio/issue-folio-e-invoice-dialog';
import { Button } from '@/components/ui/button';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { claim, confirm } from '@/routes/pos/receipt-claim';

type Props = {
    token: string;
    property: App.Data.Property.PropertySummaryData | null;
    outletName: string;
    step: 'confirm' | 'details' | 'closed';
    claimed: boolean;
};

function Confirm({ token }: { token: string }) {
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
            </Field>
            <Button
                type="submit"
                className="h-11"
                disabled={
                    form.processing ||
                    form.data.total === '' ||
                    form.data.date === ''
                }
            >
                Continue
            </Button>
        </form>
    );
}

function Details({ token }: { token: string }) {
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
    ) => (
        <Field data-invalid={!!form.errors[name]}>
            <FieldLabel htmlFor={`claim-${name}`}>{label}</FieldLabel>
            {hint && <FieldDescription>{hint}</FieldDescription>}
            <Input
                id={`claim-${name}`}
                autoComplete="off"
                value={form.data[name]}
                onChange={(event) => form.setData(name, event.target.value)}
                aria-invalid={!!form.errors[name]}
            />
            {form.errors[name] && (
                <p role="alert" className="text-sm text-destructive">
                    {form.errors[name]}
                </p>
            )}
        </Field>
    );

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
            {text('tin', 'Tax identification number (TIN)')}
            <Field>
                <FieldLabel htmlFor="claim-id-type">ID type</FieldLabel>
                <EnumSelect
                    id="claim-id-type"
                    options={ID_TYPES}
                    value={form.data.id_type}
                    onValueChange={(value) => form.setData('id_type', value)}
                />
            </Field>
            {text('id_value', 'ID number')}
            {text('email', 'Email')}
            {text('phone', 'Phone')}
            {text('address_line1', 'Address')}
            {text('city', 'City')}
            {text('postal_code', 'Postcode')}
            <Field>
                <FieldLabel htmlFor="claim-state">State</FieldLabel>
                <EnumSelect
                    id="claim-state"
                    options={MALAYSIA_STATES}
                    value={form.data.state_code}
                    onValueChange={(value) => form.setData('state_code', value)}
                />
            </Field>
            <Button type="submit" className="h-11" disabled={form.processing}>
                Send my e-invoice
            </Button>
        </form>
    );
}

export default function ReceiptClaim({
    token,
    outletName,
    step,
    claimed,
}: Props) {
    return (
        <>
            <Head title="Get your e-invoice" />
            <div className="mx-auto flex w-full max-w-md flex-col gap-6 p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="font-heading text-xl font-semibold">
                        Get your e-invoice
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {outletName}
                    </p>
                </div>
                {claimed ? (
                    <p role="status">
                        Your e-invoice was sent to LHDN MyInvois. Thank you.
                    </p>
                ) : step === 'closed' ? (
                    <p role="status">
                        This receipt can no longer be claimed here. Please ask
                        the staff for help.
                    </p>
                ) : step === 'details' ? (
                    <Details token={token} />
                ) : (
                    <Confirm token={token} />
                )}
            </div>
        </>
    );
}
