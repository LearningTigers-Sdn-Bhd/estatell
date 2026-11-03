import { Head, useForm } from '@inertiajs/react';
import { Cloud, Cpu, FileText, Globe, RefreshCw, Shield } from 'lucide-react';
import { useState } from 'react';
import EInvoiceSettingsController from '@/actions/App/Http/Controllers/Property/EInvoiceSettingsController';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { PageHeader } from '@/components/pms/page-header';
import {
    SettingsInput,
    SettingsSelect,
    SettingsSwitch,
} from '@/components/pms/settings/form-fields';
import { SettingsSection } from '@/components/pms/settings-section';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { test as testRoute } from '@/routes/settings/e-invoice';

type SettingData = {
    enabled: boolean;
    api_environment: string;
    client_id: string;
    has_client_secret: boolean;
    signature_enabled: boolean;
    signing_certificate: string | null;
    has_signing_private_key: boolean;
    supplier_name: string | null;
    supplier_tin: string | null;
    supplier_sst_registration: string | null;
    supplier_ttx_registration: string | null;
    supplier_msic_code: string | null;
    supplier_business_description: string | null;
    supplier_email: string | null;
    supplier_phone: string | null;
    supplier_address_line1: string | null;
    supplier_address_line2: string | null;
    supplier_city: string | null;
    supplier_postal_code: string | null;
    supplier_state_code: string | null;
    auto_submit_on_checkout: boolean;
    consolidation_enabled: boolean;
    effective_from: string | null;
};

type Option = { value: string; label: string };

type Props = {
    setting: SettingData | null;
    environmentOptions: Option[];
    malaysiaStates: Option[];
    propertyDefaults: {
        tin: string | null;
        registration_number: string | null;
        legal_name: string | null;
        msic_code: string | null;
        business_description: string | null;
        address_line1: string | null;
        address_line2: string | null;
        city: string | null;
        postal_code: string | null;
        state_code: string | null;
        contact_email: string | null;
        contact_phone: string | null;
    };
};

export default function EInvoiceSettings({
    setting,
    malaysiaStates,
    propertyDefaults,
}: Props) {
    const [testing, setTesting] = useState(false);
    const [apiEnvironment, setApiEnvironment] = useState(
        setting?.api_environment ?? 'sandbox',
    );
    const testForm = useForm({});

    const handleTestConnection = () => {
        setTesting(true);
        testForm.post(testRoute.url(), {
            onFinish: () => setTesting(false),
        });
    };

    return (
        <div className="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6">
            <Head title="LHDN MyInvois E-Invoice Settings" />

            <PageHeader
                title="E-Invoicing (LHDN MyInvois)"
                description="Configure Malaysia Inland Revenue Board (IRBM / LHDN) MyInvois Continuous Transaction Control integration, credentials, and digital signing."
                actions={
                    <a
                        href="/admin/e-invoice-guide"
                        target="_blank"
                        rel="noreferrer"
                        className={buttonVariants({
                            variant: 'outline',
                            size: 'sm',
                        })}
                    >
                        <FileText className="mr-1.5 size-4" />
                        Official Guidelines & Docs
                    </a>
                }
            />

            <SettingsSection
                guardUnsavedChanges
                form={EInvoiceSettingsController.update.form()}
                title="Service Status & Environment"
                description="Enable continuous e-invoicing for this property and select the targeted LHDN gateway."
                submitLabel="Save Changes"
                contentClassName="space-y-6"
            >
                {(errors) => (
                    <>
                        <SettingsSwitch
                            name="enabled"
                            label="Enable LHDN MyInvois e-invoicing"
                            description="When active, valid guest folios and AR invoices can be filed directly to LHDN MyInvois."
                            checked={setting?.enabled ?? false}
                            error={errors.enabled}
                        />

                        <div className="space-y-2">
                            <ChoiceCards
                                id="api_environment"
                                name="api_environment"
                                legend="Gateway Environment"
                                value={apiEnvironment}
                                onValueChange={setApiEnvironment}
                                columns={3}
                                choices={[
                                    {
                                        value: 'mock',
                                        label: 'Mock',
                                        description:
                                            'Deterministic local test responses without external HTTP calls.',
                                        icon: Cpu,
                                    },
                                    {
                                        value: 'sandbox',
                                        label: 'Sandbox',
                                        description:
                                            'LHDN Pre-production sandbox (preprod-api.myinvois.hasil.gov.my).',
                                        icon: Cloud,
                                    },
                                    {
                                        value: 'production',
                                        label: 'Production',
                                        description:
                                            'Live LHDN Production API (api.myinvois.hasil.gov.my).',
                                        icon: Globe,
                                    },
                                ]}
                            />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <SettingsSwitch
                                name="auto_submit_on_checkout"
                                label="Auto-file folio on checkout"
                                description="Automatically submit the e-invoice to LHDN when closing the guest folio."
                                checked={
                                    setting?.auto_submit_on_checkout ?? false
                                }
                                error={errors.auto_submit_on_checkout}
                                bordered
                            />

                            <SettingsSwitch
                                name="consolidation_enabled"
                                label="Monthly consolidation"
                                description="Automatically batch and file unconsolidated B2C stays on the 1st of each month (within 7 days)."
                                checked={setting?.consolidation_enabled ?? true}
                                error={errors.consolidation_enabled}
                                bordered
                            />
                        </div>

                        {setting?.effective_from && (
                            <p className="text-xs text-muted-foreground">
                                Active since{' '}
                                <span className="font-medium text-foreground">
                                    {new Date(
                                        setting.effective_from,
                                    ).toLocaleDateString('en-MY', {
                                        day: '2-digit',
                                        month: 'short',
                                        year: 'numeric',
                                    })}
                                </span>
                                . Folios paid prior to this date are excluded
                                from retroactive filing.
                            </p>
                        )}
                    </>
                )}
            </SettingsSection>

            <SettingsSection
                guardUnsavedChanges
                form={EInvoiceSettingsController.update.form()}
                title="LHDN MyInvois API Credentials"
                description="Client ID and Client Secret generated for your ERP system in the MyInvois Taxpayer Portal."
                submitLabel="Save Credentials"
                contentClassName="space-y-4"
            >
                {(errors) => (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <SettingsInput
                                name="client_id"
                                label="Client ID"
                                placeholder="e.g. 7f9b8c2a-..."
                                value={setting?.client_id ?? ''}
                                error={errors.client_id}
                            />

                            <SettingsInput
                                name="client_secret"
                                label="Client Secret"
                                type="password"
                                placeholder={
                                    setting?.has_client_secret
                                        ? '••••••••••••••••'
                                        : 'Paste new secret'
                                }
                                value=""
                                error={errors.client_secret}
                            />
                        </div>

                        <div className="flex items-center justify-between pt-2">
                            <p className="text-xs text-muted-foreground">
                                Credentials are encrypted at rest using AES-256
                                GCM in the Estatell database.
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={handleTestConnection}
                                disabled={testing}
                            >
                                {testing ? (
                                    <>
                                        <Spinner className="mr-1.5 size-4" />{' '}
                                        Verifying...
                                    </>
                                ) : (
                                    <>
                                        <RefreshCw className="mr-1.5 size-4" />{' '}
                                        Test LHDN Connection
                                    </>
                                )}
                            </Button>
                        </div>
                    </>
                )}
            </SettingsSection>

            <SettingsSection
                guardUnsavedChanges
                form={EInvoiceSettingsController.update.form()}
                title="Supplier Tax & Business Profile"
                description="Taxpayer identity sent in AccountingSupplierParty. Defaults to property company information if left blank."
                submitLabel="Save Supplier Profile"
                contentClassName="space-y-4"
            >
                {(errors) => (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <SettingsInput
                                name="supplier_name"
                                label="Legal Entity Name"
                                placeholder={
                                    propertyDefaults.legal_name ??
                                    'Hotel Sdn Bhd'
                                }
                                value={setting?.supplier_name ?? ''}
                                error={errors.supplier_name}
                            />

                            <SettingsInput
                                name="supplier_tin"
                                label="Tax Identification Number (TIN)"
                                placeholder={
                                    propertyDefaults.tin ?? 'C1234567890'
                                }
                                value={setting?.supplier_tin ?? ''}
                                error={errors.supplier_tin}
                            />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <SettingsInput
                                name="supplier_msic_code"
                                label="MSIC Code"
                                placeholder={
                                    propertyDefaults.msic_code ?? '55101'
                                }
                                value={setting?.supplier_msic_code ?? ''}
                                error={errors.supplier_msic_code}
                            />

                            <SettingsInput
                                name="supplier_sst_registration"
                                label="SST Registration No."
                                placeholder="e.g. S10-1808-31042523"
                                value={setting?.supplier_sst_registration ?? ''}
                                error={errors.supplier_sst_registration}
                            />

                            <SettingsInput
                                name="supplier_ttx_registration"
                                label="Tourism Tax Registration"
                                placeholder="Optional"
                                value={setting?.supplier_ttx_registration ?? ''}
                                error={errors.supplier_ttx_registration}
                            />
                        </div>

                        <SettingsInput
                            name="supplier_business_description"
                            label="Business Activity Description"
                            placeholder={
                                propertyDefaults.business_description ??
                                'Hotels and resort accommodation'
                            }
                            value={setting?.supplier_business_description ?? ''}
                            error={errors.supplier_business_description}
                        />

                        <div className="grid gap-4 sm:grid-cols-2">
                            <SettingsInput
                                name="supplier_email"
                                label="Contact Email"
                                placeholder={
                                    propertyDefaults.contact_email ??
                                    'finance@hotel.com'
                                }
                                value={setting?.supplier_email ?? ''}
                                error={errors.supplier_email}
                            />

                            <SettingsInput
                                name="supplier_phone"
                                label="Contact Phone"
                                placeholder={
                                    propertyDefaults.contact_phone ??
                                    '+60123456789'
                                }
                                value={setting?.supplier_phone ?? ''}
                                error={errors.supplier_phone}
                            />
                        </div>

                        <div className="space-y-4 pt-2">
                            <h4 className="text-sm font-semibold">
                                Registered Business Address
                            </h4>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <SettingsInput
                                    name="supplier_address_line1"
                                    label="Address Line 1"
                                    placeholder={
                                        propertyDefaults.address_line1 ?? ''
                                    }
                                    value={
                                        setting?.supplier_address_line1 ?? ''
                                    }
                                    error={errors.supplier_address_line1}
                                />
                                <SettingsInput
                                    name="supplier_address_line2"
                                    label="Address Line 2 (Optional)"
                                    placeholder={
                                        propertyDefaults.address_line2 ?? ''
                                    }
                                    value={
                                        setting?.supplier_address_line2 ?? ''
                                    }
                                    error={errors.supplier_address_line2}
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <SettingsInput
                                    name="supplier_city"
                                    label="City"
                                    placeholder={propertyDefaults.city ?? ''}
                                    value={setting?.supplier_city ?? ''}
                                    error={errors.supplier_city}
                                />
                                <SettingsInput
                                    name="supplier_postal_code"
                                    label="Postal Code"
                                    placeholder={
                                        propertyDefaults.postal_code ?? ''
                                    }
                                    value={setting?.supplier_postal_code ?? ''}
                                    error={errors.supplier_postal_code}
                                />
                                <SettingsSelect
                                    name="supplier_state_code"
                                    label="State (LHDN Code)"
                                    value={
                                        setting?.supplier_state_code ??
                                        propertyDefaults.state_code ??
                                        '12'
                                    }
                                    options={malaysiaStates}
                                    error={errors.supplier_state_code}
                                />
                            </div>
                        </div>
                    </>
                )}
            </SettingsSection>

            <SettingsSection
                guardUnsavedChanges
                form={EInvoiceSettingsController.update.form()}
                title="Cryptographic Document Signing (v1.1)"
                description="Sign UBL documents using an X.509 certificate issued by an approved Malaysian Certification Authority."
                submitLabel="Save Certificate"
                contentClassName="space-y-4"
            >
                {(errors) => (
                    <>
                        <Alert>
                            <Shield className="size-4" />
                            <AlertTitle>
                                v1.0 vs v1.1 Signing Profile
                            </AlertTitle>
                            <AlertDescription>
                                Unsigned submissions (v1.0) are fully accepted
                                by LHDN for API taxpayers today. If your
                                corporate policy requires v1.1 digital signing,
                                upload your X.509 Certificate and Private Key
                                below.
                            </AlertDescription>
                        </Alert>

                        <SettingsSwitch
                            name="signature_enabled"
                            label="Enable RSA-SHA256 Digital Signatures (v1.1)"
                            description="Embed XAdES digital signature envelope with certificate digest into UBLExtensions."
                            checked={setting?.signature_enabled ?? false}
                            error={errors.signature_enabled}
                        />

                        <div className="space-y-2">
                            <label
                                htmlFor="signing_certificate"
                                className="text-sm font-medium"
                            >
                                X.509 Certificate (PEM format)
                            </label>
                            <textarea
                                id="signing_certificate"
                                name="signing_certificate"
                                rows={4}
                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden"
                                placeholder="-----BEGIN CERTIFICATE-----&#10;MIID...&#10;-----END CERTIFICATE-----"
                                defaultValue={
                                    setting?.signing_certificate ?? ''
                                }
                            />
                            {errors.signing_certificate && (
                                <p className="text-xs text-destructive">
                                    {errors.signing_certificate}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <label
                                htmlFor="signing_private_key"
                                className="text-sm font-medium"
                            >
                                Private Key (PEM format)
                            </label>
                            <textarea
                                id="signing_private_key"
                                name="signing_private_key"
                                rows={4}
                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs shadow-xs placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden"
                                placeholder={
                                    setting?.has_signing_private_key
                                        ? '•••••••• (Private key is securely stored)'
                                        : '-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----'
                                }
                                defaultValue=""
                            />
                            {errors.signing_private_key && (
                                <p className="text-xs text-destructive">
                                    {errors.signing_private_key}
                                </p>
                            )}
                        </div>

                        <SettingsInput
                            name="signing_passphrase"
                            label="Private Key Passphrase (if encrypted)"
                            type="password"
                            placeholder="Optional passphrase"
                            value=""
                            error={errors.signing_passphrase}
                        />
                    </>
                )}
            </SettingsSection>
        </div>
    );
}
