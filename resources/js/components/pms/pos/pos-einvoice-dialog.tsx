import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    ID_TYPES,
    MALAYSIA_STATES,
    TaxNumberCheck,
} from '@/components/pms/e-invoice-fields';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { einvoice } from '@/routes/finance/pos-orders';
import { unlessRefused } from './pos-unless-refused';

/**
 * Issue an e-invoice for one walk-in sale to a named buyer (a bill over RM 10,000, or a diner who asks at the counter).
 * The buyer's tax number is checked with LHDN first, as on the folio.
 */
export function PosEInvoiceDialog({
    open,
    onClose,
    orderId,
}: {
    open: boolean;
    onClose: () => void;
    orderId: number;
}) {
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
    const [key, setKey] = useState(0);

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
    ) => (
        <Field data-invalid={!!form.errors[name]}>
            <FieldLabel htmlFor={`einv-${name}`}>{label}</FieldLabel>
            <Input
                id={`einv-${name}`}
                value={form.data[name]}
                onChange={(event) => form.setData(name, event.target.value)}
                aria-invalid={!!form.errors[name]}
            />
        </Field>
    );

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={(value) => !value && onClose()}
            title="Issue e-invoice"
            description="Send this sale to LHDN MyInvois with the buyer's own tax number."
            errors={form.errors}
            processing={form.processing}
            submitLabel="Send e-invoice"
            onSubmit={() =>
                form.post(einvoice.url({ order: orderId }), {
                    preserveScroll: true,
                    onSuccess: unlessRefused(() => {
                        setKey((value) => value + 1);
                        onClose();
                    }),
                })
            }
        >
            <div key={key} className="flex flex-col gap-4">
                {text('name', 'Buyer name')}
                <Field>
                    <FieldLabel htmlFor="einv-id-type">ID type</FieldLabel>
                    <EnumSelect
                        id="einv-id-type"
                        name="id_type"
                        options={ID_TYPES}
                        value={form.data.id_type}
                        onValueChange={(value) =>
                            form.setData('id_type', value)
                        }
                    />
                </Field>
                {text('id_value', 'ID number')}
                {text('tin', 'Tax number (TIN)')}
                <TaxNumberCheck
                    tin={form.data.tin}
                    idType={form.data.id_type}
                    idValue={form.data.id_value}
                    onUse={(tin) => form.setData('tin', tin)}
                />
                {text('email', 'Email')}
                {text('phone', 'Phone')}
                {text('address_line1', 'Address')}
                {text('city', 'City')}
                {text('postal_code', 'Postcode')}
                <Field>
                    <FieldLabel htmlFor="einv-state">State</FieldLabel>
                    <EnumSelect
                        id="einv-state"
                        name="state_code"
                        options={MALAYSIA_STATES}
                        value={form.data.state_code}
                        onValueChange={(value) =>
                            form.setData('state_code', value)
                        }
                    />
                </Field>
            </div>
        </FormOverlay>
    );
}
