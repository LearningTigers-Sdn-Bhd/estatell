import { Form } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { CountrySelect } from '@/components/pms/country-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    SettingsInput,
    SettingsPhoneInput,
    SettingsSwitch,
} from '@/components/pms/settings/form-fields';
import { StatusBadge } from '@/components/pms/status-badge';
import { Workspace } from '@/components/pms/workspace';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/portal/company';

type Company = App.Data.Portal.PortalCompanyData;

function focusFirstError(errors: Record<string, string>) {
    const first = Object.keys(errors)[0];

    if (first) {
        document.getElementById(first)?.focus();
    }
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

function FormPart({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h2 className="text-base font-medium">{title}</h2>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            <div className="grid gap-5 md:grid-cols-2">{children}</div>
        </section>
    );
}

function SummaryRow({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex items-center justify-between gap-3 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right">{children}</dd>
        </div>
    );
}

/**
 * The company's details at the property in a Workspace: the form (contact, tax numbers, billing address) in the main
 * panel, and a side card with the name, type and status (set by the property) and the "only we manage our tax and
 * billing details" switch. Both panels sit inside one form, so the switch saves with Save company details.
 */
export function PortalCompanyForm({
    propertyCode,
    company,
    countries,
}: {
    propertyCode: string;
    company: Company;
    countries: EnumOption[];
}) {
    return (
        <Form
            key={JSON.stringify(company)}
            {...update.form({ property: propertyCode })}
            options={{ preserveScroll: true }}
            onError={focusFirstError}
        >
            {({ errors, processing }) => (
                <Workspace
                    scroll="page"
                    asideLabel="Company"
                    aside={
                        <div className="flex flex-col gap-4 p-3">
                            <div className="flex flex-col gap-1">
                                <span className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Building2 aria-hidden className="size-4" />
                                    Company
                                </span>
                                <span className="font-medium">
                                    {company.name}
                                </span>
                            </div>
                            <Separator />
                            <dl className="flex flex-col gap-2">
                                <SummaryRow label="Type">
                                    {company.typeLabel}
                                </SummaryRow>
                                <SummaryRow label="Status">
                                    <StatusBadge status={company.status} />
                                </SummaryRow>
                            </dl>
                            <p className="text-xs text-muted-foreground">
                                Your name, type and status are set by the
                                property.
                            </p>
                            <Separator />
                            <SettingsSwitch
                                name="profile_managed_by_company"
                                bordered
                                label="Only we manage our tax and billing details"
                                checked={company.profileManagedByCompany}
                                error={errors.profile_managed_by_company}
                                description="On: the property sees these details read-only. Off: the property can edit them too. Saved with Save company details."
                            />
                        </div>
                    }
                >
                    <div className="flex flex-col gap-8 p-3">
                        <FormPart
                            title="Contact"
                            description="Who the property contacts about invoices and statements."
                        >
                            <SettingsInput
                                name="contact_email"
                                label="Contact email"
                                type="email"
                                placeholder="e.g. accounts@borneotravel.my"
                                value={company.contactEmail}
                                error={errors.contact_email}
                            />
                            <SettingsPhoneInput
                                name="contact_phone"
                                label="Contact phone"
                                placeholder="e.g. 012-345 6789"
                                value={company.contactPhone}
                                error={errors.contact_phone}
                            />
                        </FormPart>
                        <FormPart
                            title="Tax numbers"
                            description="Printed on your invoices."
                        >
                            <SettingsInput
                                name="brn"
                                label="Business registration no."
                                placeholder="e.g. 202001012345"
                                value={company.brn}
                                error={errors.brn}
                            />
                            <SettingsInput
                                name="tin"
                                label="Tax ID (TIN)"
                                placeholder="e.g. C12345678901"
                                value={company.tin}
                                error={errors.tin}
                            />
                            <SettingsInput
                                name="sst_registration_number"
                                label="SST registration no."
                                placeholder="e.g. W10-1808-32000123"
                                value={company.sstRegistrationNumber}
                                error={errors.sst_registration_number}
                            />
                        </FormPart>
                        <FormPart
                            title="Billing address"
                            description="Where invoices and statements are addressed."
                        >
                            <SettingsInput
                                name="billing_address_line1"
                                label="Address line 1"
                                placeholder="e.g. Lot 5, Jalan Pantai"
                                value={company.billingAddressLine1}
                                error={errors.billing_address_line1}
                            />
                            <SettingsInput
                                name="billing_address_line2"
                                label="Address line 2"
                                placeholder="e.g. Wisma Borneo, Level 3"
                                value={company.billingAddressLine2}
                                error={errors.billing_address_line2}
                            />
                            <SettingsInput
                                name="billing_city"
                                label="City"
                                placeholder="e.g. Kota Kinabalu"
                                value={company.billingCity}
                                error={errors.billing_city}
                            />
                            <SettingsInput
                                name="billing_postal_code"
                                label="Postcode"
                                placeholder="e.g. 88000"
                                value={company.billingPostalCode}
                                error={errors.billing_postal_code}
                            />
                            <SettingsInput
                                name="billing_state_code"
                                label="State"
                                placeholder="e.g. Sabah"
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
                        </FormPart>
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Save company details
                            </Button>
                        </div>
                    </div>
                </Workspace>
            )}
        </Form>
    );
}
