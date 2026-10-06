import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { PhoneInput } from '@/components/pms/phone-input';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSeparator,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { store } from '@/routes/finance/city-ledger/companies';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    types: EnumOption[];
    currency: App.Enums.Currency | null;
};

function AddCompanyForm({ open, onOpenChange, types, currency }: Props) {
    const form = useForm({
        name: '',
        type: 'corporate',
        contact_email: '',
        contact_phone: '',
        credit_limit: '',
        payment_terms_days: '',
        direct_bill_enabled: true,
    });
    const { data, errors } = form;

    const submit = () => {
        form.post(store.url(), { onSuccess: () => onOpenChange(false) });
    };

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={onOpenChange}
            title="Add company"
            description="Only the name and type are required. Add tax numbers and the billing address later on the company page."
            errors={errors}
            dirty={form.isDirty}
            discardTitle="Discard this company?"
            onSubmit={submit}
            submitLabel="Add company"
            processing={form.processing}
        >
            <FieldSet>
                <FieldLegend>Company</FieldLegend>
                <FieldDescription>
                    Who the company is and who to contact about its bills.
                </FieldDescription>
                <Field data-invalid={!!errors.name}>
                    <FieldLabel htmlFor="add-company-name">Name</FieldLabel>
                    <Input
                        id="add-company-name"
                        value={data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        maxLength={150}
                        placeholder="Borneo Travel Sdn Bhd"
                        autoComplete="off"
                        autoFocus
                        aria-invalid={!!errors.name}
                    />
                    <FieldError>{errors.name}</FieldError>
                </Field>
                <Field data-invalid={!!errors.type}>
                    <FieldLabel htmlFor="add-company-type">Type</FieldLabel>
                    <EnumSelect
                        id="add-company-type"
                        options={types}
                        value={data.type}
                        onValueChange={(value) => form.setData('type', value)}
                        aria-invalid={!!errors.type}
                        className="w-full"
                    />
                    <FieldError>{errors.type}</FieldError>
                </Field>
                <Field data-invalid={!!errors.contact_email}>
                    <FieldLabel htmlFor="add-company-email">
                        Contact email
                    </FieldLabel>
                    <Input
                        id="add-company-email"
                        type="email"
                        value={data.contact_email}
                        onChange={(event) =>
                            form.setData('contact_email', event.target.value)
                        }
                        maxLength={255}
                        placeholder="accounts@company.com"
                        autoComplete="off"
                        aria-invalid={!!errors.contact_email}
                    />
                    <FieldError>{errors.contact_email}</FieldError>
                </Field>
                <Field data-invalid={!!errors.contact_phone}>
                    <FieldLabel htmlFor="add-company-phone">
                        Contact phone
                    </FieldLabel>
                    <PhoneInput
                        id="add-company-phone"
                        value={data.contact_phone}
                        onChange={(value) =>
                            form.setData('contact_phone', value)
                        }
                        placeholder="012-345 6789"
                        invalid={!!errors.contact_phone}
                    />
                    <FieldError>{errors.contact_phone}</FieldError>
                </Field>
            </FieldSet>

            <FieldSeparator />

            <FieldSet>
                <FieldLegend>Credit</FieldLegend>
                <FieldDescription>
                    How much the company may owe and how many days it has to pay
                    an invoice.
                </FieldDescription>
                <Field data-invalid={!!errors.credit_limit}>
                    <FieldLabel htmlFor="add-company-credit_limit">
                        Credit limit
                    </FieldLabel>
                    <MoneyInput
                        id="add-company-credit_limit"
                        value={data.credit_limit}
                        onChange={(value) =>
                            form.setData('credit_limit', value)
                        }
                        currency={currency}
                        placeholder="No limit"
                        invalid={!!errors.credit_limit}
                    />
                    <FieldError>{errors.credit_limit}</FieldError>
                </Field>
                <Field data-invalid={!!errors.payment_terms_days}>
                    <FieldLabel htmlFor="add-company-payment_terms_days">
                        Payment terms (days)
                    </FieldLabel>
                    <Input
                        id="add-company-payment_terms_days"
                        inputMode="numeric"
                        className="tabular-nums"
                        value={data.payment_terms_days}
                        onChange={(event) =>
                            form.setData(
                                'payment_terms_days',
                                event.target.value,
                            )
                        }
                        placeholder="30"
                        aria-invalid={!!errors.payment_terms_days}
                    />
                    <FieldError>{errors.payment_terms_days}</FieldError>
                </Field>
                <Field orientation="horizontal" className="items-start">
                    <Switch
                        id="add-company-direct_bill_enabled"
                        checked={data.direct_bill_enabled}
                        onCheckedChange={(checked) =>
                            form.setData('direct_bill_enabled', checked)
                        }
                        className="mt-0.5"
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="add-company-direct_bill_enabled">
                            Direct billing
                        </FieldLabel>
                        <FieldDescription>
                            On: the company's stays can be charged to its
                            account and paid later through the city ledger. Off:
                            guests settle their own bills at check-out.
                        </FieldDescription>
                    </FieldContent>
                </Field>
            </FieldSet>
        </FormOverlay>
    );
}

/**
 * Adds a company with its contact and credit terms. Only name and type are required. Tax numbers and the billing
 * address are added later on the company page. After saving, the server opens the company.
 */
export function AddCompanySheet(props: Props) {
    return <AddCompanyForm key={useFreshOnOpen(props.open)} {...props} />;
}
