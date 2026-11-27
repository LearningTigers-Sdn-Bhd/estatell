import { Building2, MapPin, Save } from 'lucide-react';
import EInvoiceSettingsController from '@/actions/App/Http/Controllers/Property/EInvoiceSettingsController';
import type { EnumOption } from '@/components/pms/enum-select';
import type {
    EInvoicePropertyDefaults,
    EInvoiceSetting,
} from '@/components/pms/einvoice/settings-types';
import { OptionalBadge } from '@/components/pms/optional-badge';
import { PanelHeading } from '@/components/pms/panel-heading';
import {
    SettingsInput,
    SettingsSelect,
} from '@/components/pms/settings/form-fields';
import { SettingsSection } from '@/components/pms/settings-section';
import { FieldGroup } from '@/components/ui/field';

/**
 * Settings › E-invoicing › Business details: the hotel details printed on every e-invoice. A field the settings leave
 * empty shows the property's own value. Saves from the floating Save bar.
 */
export function EInvoiceBusinessForm({
    setting,
    propertyDefaults,
    malaysiaStates,
}: {
    setting: EInvoiceSetting | null;
    propertyDefaults: EInvoicePropertyDefaults;
    malaysiaStates: EnumOption[];
}) {
    return (
        <SettingsSection
            guardUnsavedChanges
            saveBar
            form={EInvoiceSettingsController.updateBusiness.form()}
            title="Business details"
            icon={Building2}
            description="Printed on every e-invoice. Check each one against your SSM and tax records."
            headerClassName="ps-2 pt-2"
            contentClassName="flex flex-col gap-6 pb-6"
            submitLabel="Save"
            submitIcon={Save}
        >
            {(errors) => (
                <>
                    <FieldGroup className="grid md:grid-cols-2">
                        <SettingsInput
                            name="supplier_name"
                            label="Registered business name"
                            value={
                                setting?.supplier_name ??
                                propertyDefaults.legal_name
                            }
                            placeholder="e.g. Seri Pantai Resort Sdn Bhd"
                            description="Must exactly match your SSM registration."
                            error={errors.supplier_name}
                        />
                        <SettingsInput
                            name="supplier_tin"
                            label="Tax number (TIN)"
                            value={
                                setting?.supplier_tin ?? propertyDefaults.tin
                            }
                            placeholder="e.g. C1234567890"
                            description="The company's LHDN tax number, starting with C (or IG for a sole owner)."
                            error={errors.supplier_tin}
                        />
                        <SettingsInput
                            name="supplier_brn"
                            label="Company registration number (SSM)"
                            value={
                                setting?.supplier_brn ?? propertyDefaults.brn
                            }
                            placeholder="e.g. 202001012345"
                            description="The 12-digit number on your SSM certificate."
                            error={errors.supplier_brn}
                        />
                        <SettingsInput
                            name="supplier_msic_code"
                            label="MSIC code"
                            value={
                                setting?.supplier_msic_code ??
                                propertyDefaults.msic_code
                            }
                            placeholder="e.g. 55101"
                            description="5-digit business activity code: 55101 for hotels, 55103 for homestays."
                            error={errors.supplier_msic_code}
                        />
                        <SettingsInput
                            name="supplier_sst_registration"
                            label={
                                <>
                                    SST registration number <OptionalBadge />
                                </>
                            }
                            value={setting?.supplier_sst_registration ?? ''}
                            placeholder="e.g. W10-1808-12345678"
                            description="Needed when the hotel charges SST."
                            error={errors.supplier_sst_registration}
                        />
                        <SettingsInput
                            name="supplier_ttx_registration"
                            label={
                                <>
                                    Tourism tax registration number{' '}
                                    <OptionalBadge />
                                </>
                            }
                            value={setting?.supplier_ttx_registration ?? ''}
                            placeholder="e.g. TTX-12345678"
                            description="Needed when the hotel charges tourism tax."
                            error={errors.supplier_ttx_registration}
                        />
                        <div className="md:col-span-2">
                            <SettingsInput
                                name="supplier_business_description"
                                label="Business activity description"
                                value={
                                    setting?.supplier_business_description ??
                                    propertyDefaults.business_description
                                }
                                placeholder="e.g. Hotel accommodation"
                                error={errors.supplier_business_description}
                            />
                        </div>
                    </FieldGroup>

                    <section className="flex flex-col gap-5 pt-4">
                        <PanelHeading
                            icon={MapPin}
                            title="Address and contact"
                            description="Where LHDN and buyers can reach the hotel."
                        />
                        <FieldGroup>
                            <SettingsInput
                                name="supplier_address_line1"
                                label="Address line 1"
                                value={
                                    setting?.supplier_address_line1 ??
                                    propertyDefaults.address_line1
                                }
                                placeholder="e.g. Lot 12, Jalan Pantai Cenang"
                                error={errors.supplier_address_line1}
                            />
                            <SettingsInput
                                name="supplier_address_line2"
                                label={
                                    <>
                                        Address line 2 <OptionalBadge />
                                    </>
                                }
                                value={
                                    setting?.supplier_address_line2 ??
                                    propertyDefaults.address_line2
                                }
                                placeholder="e.g. Kampung Mawar"
                                error={errors.supplier_address_line2}
                            />
                            <FieldGroup className="grid md:grid-cols-3">
                                <SettingsInput
                                    name="supplier_city"
                                    label="City"
                                    value={
                                        setting?.supplier_city ??
                                        propertyDefaults.city
                                    }
                                    placeholder="e.g. Langkawi"
                                    error={errors.supplier_city}
                                />
                                <SettingsInput
                                    name="supplier_postal_code"
                                    label="Postcode"
                                    value={
                                        setting?.supplier_postal_code ??
                                        propertyDefaults.postal_code
                                    }
                                    placeholder="e.g. 07000"
                                    error={errors.supplier_postal_code}
                                />
                                <SettingsSelect
                                    name="supplier_state_code"
                                    label="State"
                                    value={
                                        setting?.supplier_state_code ??
                                        propertyDefaults.state_code ??
                                        '12'
                                    }
                                    options={malaysiaStates}
                                    error={errors.supplier_state_code}
                                />
                            </FieldGroup>
                            <FieldGroup className="grid md:grid-cols-2">
                                <SettingsInput
                                    name="supplier_email"
                                    label="Email for e-invoice matters"
                                    type="email"
                                    value={
                                        setting?.supplier_email ??
                                        propertyDefaults.contact_email
                                    }
                                    placeholder="e.g. accounts@seripantai.com.my"
                                    error={errors.supplier_email}
                                />
                                <SettingsInput
                                    name="supplier_phone"
                                    label="Phone"
                                    value={
                                        setting?.supplier_phone ??
                                        propertyDefaults.contact_phone
                                    }
                                    placeholder="e.g. +60123456789"
                                    description="With the country code."
                                    error={errors.supplier_phone}
                                />
                            </FieldGroup>
                        </FieldGroup>
                    </section>
                </>
            )}
        </SettingsSection>
    );
}
