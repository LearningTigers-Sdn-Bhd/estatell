import { router, useForm } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Clock, ExternalLink } from 'lucide-react';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { CountrySelect } from '@/components/pms/country-select';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    ID_TYPES,
    MALAYSIA_STATES,
} from '@/components/pms/folio/issue-folio-e-invoice-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/concierge/e-invoice';

const PERSON_ID_TYPES = ID_TYPES.filter((type) =>
    ['NRIC', 'PASSPORT'].includes(type.value),
);

/** The e-invoice of a stay: request it with your tax details, then open it once LHDN has validated it. */
export default function ConciergeEInvoice({
    token,
    back,
    eInvoice,
    countries,
}: {
    token: string;
    back: { href: string; label: string };
    eInvoice: App.Data.Concierge.ConciergeEInvoiceData;
    countries: EnumOption[];
}) {
    return (
        <GuestFrame
            back={back}
            title="E-invoice"
            description="A tax invoice for your stay, validated by LHDN."
        >
            <div className="flex max-w-2xl flex-col gap-6">
                <section className="flex flex-col gap-3 rounded-2xl bg-muted/50 p-6">
                    <h2 className="flex items-center gap-2 text-lg font-semibold">
                        {eInvoice.state === 'valid' ? (
                            <CircleCheck
                                className="size-5 text-primary"
                                aria-hidden
                            />
                        ) : eInvoice.state === 'processing' ? (
                            <Clock
                                className="size-5 text-primary"
                                aria-hidden
                            />
                        ) : null}
                        {eInvoice.title}
                    </h2>
                    <p className="text-sm text-pretty text-muted-foreground">
                        {eInvoice.message}
                    </p>
                    {eInvoice.invoiceNumber && (
                        <p className="text-sm">
                            <span className="text-muted-foreground">
                                Invoice{' '}
                            </span>
                            {eInvoice.invoiceNumber}
                        </p>
                    )}
                    {eInvoice.viewUrl && (
                        <a
                            href={eInvoice.viewUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex min-h-12 items-center justify-center gap-2 self-start rounded-lg bg-primary px-5 text-sm font-medium text-primary-foreground hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                        >
                            Open the e-invoice
                            <ExternalLink className="size-4" aria-hidden />
                            <span className="sr-only">Opens in a new tab</span>
                        </a>
                    )}
                    {eInvoice.state === 'processing' && (
                        <Button
                            type="button"
                            variant="outline"
                            className="self-start"
                            onClick={() => router.reload()}
                        >
                            Refresh
                        </Button>
                    )}
                </section>

                {eInvoice.buyer && (
                    <RequestForm
                        token={token}
                        buyer={eInvoice.buyer}
                        rejection={eInvoice.rejection}
                        countries={countries}
                    />
                )}
            </div>
        </GuestFrame>
    );
}

function RequestForm({
    token,
    buyer,
    rejection,
    countries,
}: {
    token: string;
    buyer: App.Data.Concierge.ConciergeEInvoiceBuyerData;
    rejection: string | null;
    countries: EnumOption[];
}) {
    const form = useForm({
        name: buyer.name,
        id_type: buyer.idType,
        id_value: buyer.idValue,
        tin: buyer.tin,
        email: buyer.email,
        phone: buyer.phone,
        address_line1: buyer.addressLine1,
        address_line2: buyer.addressLine2,
        city: buyer.city,
        postal_code: buyer.postalCode,
        state_code: buyer.stateCode,
        country_code: buyer.countryCode,
    });
    const { data, errors, setData } = form;
    const isForeign = data.country_code !== 'MY';

    const text = (
        key: keyof typeof data,
        label: string,
        options: { required?: boolean; hint?: string; type?: string } = {},
    ) => (
        <Field data-invalid={!!errors[key]}>
            <FieldLabel htmlFor={`einvoice-${key}`}>
                {label}
                {options.required ? ' *' : ''}
            </FieldLabel>
            <Input
                id={`einvoice-${key}`}
                type={options.type}
                value={data[key]}
                onChange={(event) => setData(key, event.target.value)}
                required={options.required}
                aria-invalid={!!errors[key]}
                className="h-12 text-base"
            />
            {options.hint && (
                <FieldDescription>{options.hint}</FieldDescription>
            )}
            <FieldError>{errors[key]}</FieldError>
        </Field>
    );

    return (
        <form
            className="flex flex-col gap-6 rounded-2xl border border-border/40 bg-card p-6"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(store.url({ token }), { preserveScroll: true });
            }}
        >
            {rejection && (
                <Alert variant="destructive">
                    <CircleAlert />
                    <AlertDescription>{rejection}</AlertDescription>
                </Alert>
            )}
            <FieldGroup>
                {text('name', 'Name on the e-invoice', {
                    required: true,
                    hint: 'Your full name as on your IC or passport.',
                })}
                <FieldGroup className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!errors.id_type}>
                        <FieldLabel htmlFor="einvoice-id_type">
                            ID type *
                        </FieldLabel>
                        <EnumSelect
                            id="einvoice-id_type"
                            options={PERSON_ID_TYPES}
                            value={data.id_type}
                            onValueChange={(value) => setData('id_type', value)}
                            className="w-full"
                        />
                        <FieldError>{errors.id_type}</FieldError>
                    </Field>
                    {text('id_value', 'ID number', { required: true })}
                </FieldGroup>
                {text(
                    'tin',
                    isForeign
                        ? 'Malaysian tax number (optional)'
                        : 'Tax number (TIN) *',
                    {
                        required: !isForeign,
                        hint: isForeign
                            ? 'Leave it empty if you have none.'
                            : 'Starts with IG for a person. LHDN checks it against your IC.',
                    },
                )}
                <Field data-invalid={!!errors.country_code}>
                    <FieldLabel htmlFor="einvoice-country_code">
                        Country *
                    </FieldLabel>
                    <CountrySelect
                        id="einvoice-country_code"
                        value={data.country_code}
                        options={countries}
                        invalid={!!errors.country_code}
                        onValueChange={(country) => {
                            setData((current) => ({
                                ...current,
                                country_code: country,
                                state_code:
                                    country !== 'MY'
                                        ? '17'
                                        : current.state_code === '17'
                                          ? ''
                                          : current.state_code,
                            }));
                        }}
                    />
                    <FieldError>{errors.country_code}</FieldError>
                </Field>
                {text('address_line1', 'Street address')}
                <FieldGroup className="grid gap-4 sm:grid-cols-2">
                    {text('city', 'City', { required: true })}
                    {!isForeign && (
                        <Field data-invalid={!!errors.state_code}>
                            <FieldLabel htmlFor="einvoice-state_code">
                                State *
                            </FieldLabel>
                            <EnumSelect
                                id="einvoice-state_code"
                                options={MALAYSIA_STATES.filter(
                                    (state) => state.value !== '17',
                                )}
                                value={data.state_code}
                                onValueChange={(value) =>
                                    setData('state_code', value)
                                }
                                className="w-full"
                            />
                            <FieldError>{errors.state_code}</FieldError>
                        </Field>
                    )}
                </FieldGroup>
                {text('postal_code', 'Postcode')}
                <FieldGroup className="grid gap-4 sm:grid-cols-2">
                    {text('email', 'Email (optional)', { type: 'email' })}
                    {text('phone', 'Phone (optional)', { type: 'tel' })}
                </FieldGroup>
            </FieldGroup>
            <Button
                type="submit"
                size="lg"
                className="h-12 w-full rounded-lg"
                disabled={form.processing}
                focusableWhenDisabled
            >
                {form.processing && <Spinner data-icon="inline-start" />}
                Request my e-invoice
            </Button>
        </form>
    );
}
