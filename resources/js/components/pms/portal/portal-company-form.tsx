import { useState } from 'react';
import { CountrySelect } from '@/components/pms/country-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { SettingsSection } from '@/components/pms/settings-section';
import {
    SettingsInput,
    SettingsSwitch,
} from '@/components/pms/settings/form-fields';
import { StatusBadge } from '@/components/pms/status-badge';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { update } from '@/routes/portal/company';

type Company = App.Data.Portal.PortalCompanyData;

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
 * The company's name, type and status (read-only), then the one form the company saves: the "we manage our tax and
 * billing details" tick, the contact, the tax numbers and the billing address.
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
        <>
            <dl className="grid max-w-3xl gap-3 sm:grid-cols-3">
                <div className="flex flex-col gap-1 rounded-lg border p-3">
                    <dt className="text-xs text-muted-foreground">Company</dt>
                    <dd className="text-sm font-medium">{company.name}</dd>
                </div>
                <div className="flex flex-col gap-1 rounded-lg border p-3">
                    <dt className="text-xs text-muted-foreground">Type</dt>
                    <dd className="text-sm">{company.typeLabel}</dd>
                </div>
                <div className="flex flex-col gap-1 rounded-lg border p-3">
                    <dt className="text-xs text-muted-foreground">Status</dt>
                    <dd className="text-sm">
                        <StatusBadge status={company.status} />
                    </dd>
                </div>
            </dl>

            <SettingsSection
                key={JSON.stringify(company)}
                form={update.form({ property: propertyCode })}
                title="Contact, tax numbers and billing address"
                description="Invoices and statements use these details. Your name, type and status are set by the property."
                submitLabel="Save company details"
            >
                {(errors) => (
                    <>
                        <SettingsSwitch
                            name="profile_managed_by_company"
                            label="Only we manage our tax and billing details"
                            checked={company.profileManagedByCompany}
                            error={errors.profile_managed_by_company}
                            description="Ticked, the property sees these details read-only and cannot change them. Untick it to let the property edit them."
                        />
                        <SettingsInput
                            name="contact_email"
                            label="Contact email"
                            type="email"
                            placeholder="e.g. accounts@borneotravel.my"
                            value={company.contactEmail}
                            error={errors.contact_email}
                        />
                        <SettingsInput
                            name="contact_phone"
                            label="Contact phone"
                            placeholder="e.g. +60 12 345 6789"
                            value={company.contactPhone}
                            error={errors.contact_phone}
                        />
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
                    </>
                )}
            </SettingsSection>
        </>
    );
}
