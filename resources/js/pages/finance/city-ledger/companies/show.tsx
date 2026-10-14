import { Form, Head } from '@inertiajs/react';
import { CreditCard, FileText, Info, Tag, Wallet } from 'lucide-react';
import { useState } from 'react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { billingChoices } from '@/components/pms/city-ledger/billing-choices';
import { CompanyStatement } from '@/components/pms/city-ledger/company-statement';
import { ArPaymentsTab } from '@/components/pms/city-ledger/ar-payments-tab';
import { CompanyRatesForm } from '@/components/pms/city-ledger/company-rates-form';
import { CountrySelect } from '@/components/pms/country-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { SettingsSection } from '@/components/pms/settings-section';
import {
    SettingsInput,
    SettingsSelect,
} from '@/components/pms/settings/form-fields';
import { StatusBadge } from '@/components/pms/status-badge';
import { TabLinks } from '@/components/pms/tab-links';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    credit as creditTab,
    index,
    payments as paymentsTab,
    rates as ratesTab,
    reactivate,
    statement as statementTab,
    show,
    suspend,
    update,
} from '@/routes/finance/city-ledger/companies';
import { update as billingUpdate } from '@/routes/finance/city-ledger/companies/billing';

type Company = App.Data.CityLedger.CompanyData;

const overLimitBadge: App.Data.Shared.BadgeData = {
    value: 'over_limit',
    label: 'Over limit',
    tone: 'danger',
};
type Tab = 'details' | 'credit' | 'rates' | 'payments' | 'statement';

/**
 * A value the company keeps up to date in the company portal. Shown, never sent with a form.
 */
function ReadOnlyField({
    id,
    label,
    value,
    multiline = false,
}: {
    id: string;
    label: string;
    value: string | null;
    multiline?: boolean;
}) {
    return (
        <Field className={multiline ? 'md:col-span-2' : undefined}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            {multiline ? (
                <Textarea
                    id={id}
                    value={value ?? ''}
                    placeholder="Not provided yet"
                    rows={4}
                    readOnly
                    className="resize-none bg-muted"
                />
            ) : (
                <Input
                    id={id}
                    value={value ?? ''}
                    placeholder="Not provided yet"
                    readOnly
                    className="bg-muted"
                />
            )}
        </Field>
    );
}

/**
 * The tax numbers and billing address, read-only, once the company manages them in the company portal.
 */
function TaxAndBillingSection({
    company,
    description,
}: {
    company: Company;
    description: string;
}) {
    return (
        <section className="flex flex-col gap-5">
            <header className="space-y-1">
                <h3 className="text-base font-medium">
                    Tax numbers and billing address
                </h3>
                <p className="text-sm text-muted-foreground">{description}</p>
            </header>
            <div className="grid gap-5 md:grid-cols-2">
                <ReadOnlyField
                    id="brn"
                    label="Business registration no."
                    value={company.brn}
                />
                <ReadOnlyField
                    id="tin"
                    label="Tax ID (TIN)"
                    value={company.tin}
                />
                <ReadOnlyField
                    id="sst_registration_number"
                    label="SST registration no."
                    value={company.sstRegistrationNumber}
                />
                <ReadOnlyField
                    id="billing_address"
                    label="Billing address"
                    value={company.billingAddress}
                    multiline
                />
            </div>
        </section>
    );
}

/**
 * A country picker for a plain `<Form>`: the picked code is sent in a hidden input.
 */
function CountryField({
    name,
    label,
    value,
    options,
    error,
}: {
    name: string;
    label: string;
    value: string | null;
    options: EnumOption[];
    error?: string;
}) {
    const [code, setCode] = useState(value ?? '');

    return (
        <Field>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>
            <CountrySelect
                id={name}
                value={code}
                onValueChange={setCode}
                options={options}
                invalid={!!error}
            />
            <input type="hidden" name={name} value={code} />
            <FieldError>{error}</FieldError>
        </Field>
    );
}

/**
 * How the company pays, then its credit limit and terms for direct billing. With Pay at check-out the credit fields are
 * hidden but stay in the form, so their values are kept.
 */
function CreditFields({
    company,
    currency,
    readOnly,
    errors,
}: {
    company: Company;
    currency: App.Data.Property.CurrencyData | null;
    readOnly: boolean;
    errors: Record<string, string>;
}) {
    const [billing, setBilling] = useState(
        company.directBillEnabled ? '1' : '0',
    );

    return (
        <>
            <ChoiceCards
                id="direct_bill_enabled"
                name="direct_bill_enabled"
                legend="How does this company pay?"
                choices={billingChoices}
                value={billing}
                onValueChange={setBilling}
                disabled={readOnly}
                error={errors.direct_bill_enabled}
                className="md:col-span-2"
            />
            <div className={billing === '1' ? 'contents' : 'hidden'}>
                <SettingsInput
                    name="credit_limit"
                    placeholder="No limit"
                    label={`Credit limit${currency ? ` (${currency.code})` : ''}`}
                    value={company.creditLimit}
                    disabled={readOnly}
                    error={errors.credit_limit}
                    description="Leave empty for no limit."
                />
                <SettingsInput
                    name="payment_terms_days"
                    placeholder="30"
                    label="Payment terms (days)"
                    type="number"
                    min={0}
                    max={365}
                    value={company.paymentTermsDays}
                    disabled={readOnly}
                    error={errors.payment_terms_days}
                    description="Days the company has to pay an invoice."
                />
            </div>
        </>
    );
}

/**
 * The tax numbers and billing address as hotel staff edit them, until the company manages them in the portal.
 */
function TaxAndBillingForm({
    company,
    countries,
}: {
    company: Company;
    countries: EnumOption[];
}) {
    return (
        <SettingsSection
            key={`billing-${company.id}`}
            form={billingUpdate.form({ company: company.id })}
            title="Tax numbers and billing address"
            description="Optional. Printed on city ledger invoices. The company can take these over in the company portal."
            submitLabel="Save tax and address"
        >
            {(errors) => (
                <>
                    <SettingsInput
                        name="brn"
                        label="Business registration no."
                        placeholder="202001012345"
                        value={company.brn}
                        error={errors.brn}
                    />
                    <SettingsInput
                        name="tin"
                        label="Tax ID (TIN)"
                        placeholder="C12345678901"
                        value={company.tin}
                        error={errors.tin}
                    />
                    <SettingsInput
                        name="sst_registration_number"
                        label="SST registration no."
                        placeholder="W10-1808-32000123"
                        value={company.sstRegistrationNumber}
                        error={errors.sst_registration_number}
                    />
                    <SettingsInput
                        name="billing_address_line1"
                        label="Address line 1"
                        placeholder="Lot 5, Jalan Pantai"
                        value={company.billingAddressLine1}
                        error={errors.billing_address_line1}
                    />
                    <SettingsInput
                        name="billing_address_line2"
                        label="Address line 2"
                        placeholder="Wisma Borneo, Level 3"
                        value={company.billingAddressLine2}
                        error={errors.billing_address_line2}
                    />
                    <SettingsInput
                        name="billing_city"
                        label="City"
                        placeholder="Kota Kinabalu"
                        value={company.billingCity}
                        error={errors.billing_city}
                    />
                    <SettingsInput
                        name="billing_postal_code"
                        label="Postcode"
                        placeholder="88000"
                        value={company.billingPostalCode}
                        error={errors.billing_postal_code}
                    />
                    <SettingsInput
                        name="billing_state_code"
                        label="State"
                        placeholder="Sabah"
                        value={company.billingStateCode}
                        error={errors.billing_state_code}
                    />
                    <CountryField
                        name="billing_country_code"
                        label="Country"
                        value={company.billingCountryCode}
                        options={countries}
                        error={errors.billing_country_code}
                    />
                </>
            )}
        </SettingsSection>
    );
}

function CreditFigure({
    label,
    amount,
    currency,
    danger = false,
}: {
    label: string;
    amount: string;
    currency: App.Data.Property.CurrencyData | null;
    danger?: boolean;
}) {
    return (
        <div className="flex flex-col gap-1 rounded-lg border p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={
                    danger
                        ? 'text-sm font-medium text-destructive'
                        : 'text-sm font-medium'
                }
            >
                <MoneyText amount={amount} currency={currency} />
            </dd>
        </div>
    );
}

function StatusAction({ company }: { company: Company }) {
    const [open, setOpen] = useState(false);

    if (company.status.value === 'suspended') {
        return (
            <Form
                {...reactivate.form({ company: company.id })}
                options={{ preserveScroll: true }}
            >
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                        focusableWhenDisabled
                    >
                        {processing && <Spinner data-icon="inline-start" />}
                        Reactivate
                    </Button>
                )}
            </Form>
        );
    }

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant="outline" />}>
                Suspend
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Suspend {company.name}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        The company cannot be picked on new bookings. Existing
                        reservations keep it. You can reactivate it later.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...suspend.form({ company: company.id })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Suspend company
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

export default function CompanyShow({
    company,
    tab,
    currency,
    canManage,
    types = [],
    countries = [],
    ratePlans = [],
    linkedPlanIds = [],
    payments = [],
    openInvoices = [],
    methods = [],
    canRecordPayment = false,
    statement = null,
}: {
    company: Company;
    tab: Tab;
    currency: App.Data.Property.CurrencyData | null;
    canManage: boolean;
    types?: EnumOption[];
    countries?: EnumOption[];
    ratePlans?: App.Data.Rates.RatePlanData[];
    linkedPlanIds?: number[];
    payments?: App.Data.CityLedger.ArPaymentRowData[];
    openInvoices?: App.Data.CityLedger.OpenArInvoiceData[];
    methods?: App.Data.CityLedger.ArPaymentMethodOptionData[];
    canRecordPayment?: boolean;
    statement?: App.Data.CityLedger.StatementData | null;
}) {
    const readOnly = !canManage;
    const form = (section: 'details' | 'credit') =>
        update.form({ company: company.id, section });

    return (
        <>
            <Head title={company.name} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={company.name}
                    description={company.typeLabel}
                    back={{ href: index(), label: 'Companies' }}
                    badge={
                        <>
                            <StatusBadge status={company.status} />
                            {company.credit?.overLimit && (
                                <StatusBadge status={overLimitBadge} />
                            )}
                        </>
                    }
                    actions={canManage && <StatusAction company={company} />}
                />

                <TabLinks
                    activeTab={tab}
                    className="flex flex-col gap-4"
                    panelClassName="flex flex-col gap-6"
                    panelWidth={
                        tab === 'details' || tab === 'credit'
                            ? 'default'
                            : 'full'
                    }
                    tabs={[
                        {
                            value: 'details',
                            label: 'Details',
                            icon: Info,
                            href: show({ company: company.id }),
                        },
                        {
                            value: 'credit',
                            label: 'Credit',
                            icon: CreditCard,
                            href: creditTab({ company: company.id }),
                        },
                        {
                            value: 'rates',
                            label: 'Rates',
                            icon: Tag,
                            href: ratesTab({ company: company.id }),
                        },
                        {
                            value: 'payments',
                            label: 'Payments',
                            icon: Wallet,
                            href: paymentsTab({ company: company.id }),
                        },
                        {
                            value: 'statement',
                            label: 'Statement',
                            icon: FileText,
                            href: statementTab({ company: company.id }),
                        },
                    ]}
                >
                    {tab === 'details' && (
                        <SettingsSection
                            key={`details-${company.id}`}
                            form={form('details')}
                            title="Details"
                            description="Who the company is and who to contact about its bills."
                            submitLabel="Save details"
                            readOnly={readOnly}
                        >
                            {(errors) => (
                                <>
                                    <SettingsInput
                                        name="name"
                                        placeholder="Borneo Travel Sdn Bhd"
                                        label="Name"
                                        value={company.name}
                                        disabled={readOnly}
                                        error={errors.name}
                                    />
                                    <SettingsSelect
                                        name="type"
                                        label="Type"
                                        value={company.type}
                                        options={types}
                                        disabled={readOnly}
                                        error={errors.type}
                                    />
                                    <SettingsInput
                                        name="contact_email"
                                        placeholder="accounts@company.com"
                                        label="Contact email"
                                        type="email"
                                        value={company.contactEmail}
                                        disabled={readOnly}
                                        error={errors.contact_email}
                                    />
                                    <SettingsInput
                                        name="contact_phone"
                                        placeholder="012-345 6789"
                                        label="Contact phone"
                                        type="tel"
                                        value={company.contactPhone}
                                        disabled={readOnly}
                                        error={errors.contact_phone}
                                        description="Without a country code, the number is read as a local number."
                                    />
                                </>
                            )}
                        </SettingsSection>
                    )}
                    {tab === 'details' &&
                        (company.profileManagedByCompany || readOnly ? (
                            <TaxAndBillingSection
                                company={company}
                                description={
                                    company.profileManagedByCompany
                                        ? 'The company manages these details in the company portal. Printed on city ledger invoices.'
                                        : 'Printed on city ledger invoices.'
                                }
                            />
                        ) : (
                            <TaxAndBillingForm
                                company={company}
                                countries={countries}
                            />
                        ))}
                    {tab === 'credit' && company.credit && (
                        <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <CreditFigure
                                label="Open invoices"
                                currency={currency}
                                amount={company.credit.openAr}
                            />
                            <CreditFigure
                                label="Open folios"
                                currency={currency}
                                amount={company.credit.inHouse}
                            />
                            <CreditFigure
                                label="Exposure"
                                currency={currency}
                                amount={company.credit.exposure}
                                danger={company.credit.overLimit}
                            />
                            {company.credit.headroom === null ? (
                                <div className="flex flex-col gap-1 rounded-lg border p-3">
                                    <dt className="text-xs text-muted-foreground">
                                        Headroom
                                    </dt>
                                    <dd className="text-sm text-muted-foreground">
                                        No limit
                                    </dd>
                                </div>
                            ) : (
                                <CreditFigure
                                    label="Headroom"
                                    currency={currency}
                                    amount={company.credit.headroom}
                                    danger={company.credit.overLimit}
                                />
                            )}
                        </dl>
                    )}
                    {tab === 'credit' && (
                        <SettingsSection
                            key={`credit-${company.id}`}
                            form={form('credit')}
                            title="Credit"
                            description="How the company pays. With direct billing, how much it may owe and how many days it has to pay an invoice."
                            submitLabel="Save credit"
                            readOnly={readOnly}
                        >
                            {(errors) => (
                                <CreditFields
                                    company={company}
                                    currency={currency}
                                    readOnly={readOnly}
                                    errors={errors}
                                />
                            )}
                        </SettingsSection>
                    )}
                    {tab === 'statement' && statement && (
                        <CompanyStatement
                            key={`statement-${company.id}`}
                            companyId={company.id}
                            statement={statement}
                            currency={currency}
                        />
                    )}
                    {tab === 'payments' && (
                        <ArPaymentsTab
                            key={`payments-${company.id}`}
                            companyId={company.id}
                            payments={payments}
                            openInvoices={openInvoices}
                            methods={methods}
                            currency={currency}
                            canRecordPayment={canRecordPayment}
                        />
                    )}
                    {tab === 'rates' && (
                        <CompanyRatesForm
                            key={`rates-${company.id}`}
                            companyId={company.id}
                            ratePlans={ratePlans}
                            linkedPlanIds={linkedPlanIds}
                            readOnly={readOnly}
                        />
                    )}
                </TabLinks>
            </div>
        </>
    );
}

CompanyShow.layout = (props: { company: Company }) => ({
    breadcrumbs: [
        { title: 'Companies', href: index() },
        {
            title: props.company.name,
            href: show({ company: props.company.id }),
        },
    ],
});
