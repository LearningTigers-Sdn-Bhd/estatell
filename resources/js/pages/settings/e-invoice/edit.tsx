import { Head, useForm } from '@inertiajs/react';
import { Cloud, Cpu, ExternalLink, Globe, Plug, Shield } from 'lucide-react';
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
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { FieldGroup, FieldLegend, FieldSet } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { profile } from '@/routes/settings';
import { edit, test as testRoute } from '@/routes/settings/e-invoice';

type SettingData = {
    enabled: boolean;
    api_environment: string;
    client_id: string;
    has_client_secret: boolean;
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
    const [apiEnvironment, setApiEnvironment] = useState(
        setting?.api_environment ?? 'sandbox',
    );
    const testForm = useForm({});

    const isConfigured = Boolean(
        setting?.client_id && setting?.has_client_secret,
    );
    const portalUrl =
        apiEnvironment === 'production'
            ? 'https://myinvois.hasil.gov.my'
            : 'https://preprod.myinvois.hasil.gov.my';

    return (
        <>
            <Head title="E-Invoicing" />

            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="E-Invoicing"
                    description="Connect your property to the Inland Revenue Board of Malaysia (LHDN / IRBM) MyInvois gateway for Continuous Transaction Control (CTC)."
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="flex flex-col gap-4 lg:col-span-2">
                        <SettingsSection
                            guardUnsavedChanges
                            form={EInvoiceSettingsController.update.form()}
                            title="Connection & Tax Profile"
                            description="API credentials, environment, and supplier identity transmitted on every e-invoice submission."
                            submitLabel="Save settings"
                            contentClassName="flex flex-col gap-6"
                        >
                            {(errors) => (
                                <>
                                    <FieldSet>
                                        <FieldLegend>
                                            Environment & Automation
                                        </FieldLegend>
                                        <ChoiceCards
                                            id="api_environment"
                                            name="api_environment"
                                            legend="Gateway environment"
                                            columns={3}
                                            choices={[
                                                {
                                                    value: 'mock',
                                                    label: 'Mock',
                                                    description:
                                                        'Local simulator for sandbox development and fast tests.',
                                                    icon: Cpu,
                                                },
                                                {
                                                    value: 'sandbox',
                                                    label: 'Sandbox',
                                                    description:
                                                        'LHDN Pre-production environment for integration testing.',
                                                    icon: Cloud,
                                                },
                                                {
                                                    value: 'production',
                                                    label: 'Production',
                                                    description:
                                                        'LHDN Live gateway for legally binding statutory e-invoices.',
                                                    icon: Globe,
                                                },
                                            ]}
                                            value={apiEnvironment}
                                            onValueChange={setApiEnvironment}
                                            error={errors.api_environment}
                                        />

                                        {apiEnvironment === 'production' &&
                                            setting?.api_environment !==
                                                'production' && (
                                                <div className="flex flex-col gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm">
                                                    <p className="font-semibold text-amber-600 dark:text-amber-400">
                                                        Switching to Production
                                                        Gateway
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        Submissions in
                                                        Production file legally
                                                        binding statutory
                                                        e-invoices directly with
                                                        LHDN Malaysia.
                                                    </p>
                                                    <label className="flex cursor-pointer items-center gap-2 pt-1">
                                                        <input
                                                            type="checkbox"
                                                            name="confirm_production"
                                                            value="1"
                                                            className="size-4 rounded border-input text-primary focus:ring-primary"
                                                        />
                                                        <span className="text-xs font-medium">
                                                            I confirm that I
                                                            understand this
                                                            property will submit
                                                            real, legally
                                                            binding tax
                                                            documents to LHDN.
                                                        </span>
                                                    </label>
                                                    {errors.confirm_production && (
                                                        <p className="text-xs text-destructive">
                                                            {
                                                                errors.confirm_production
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                            )}

                                        <div className="grid gap-4 md:grid-cols-2">
                                            <SettingsSwitch
                                                bordered
                                                name="enabled"
                                                label="Enable e-invoicing"
                                                checked={
                                                    setting?.enabled ?? false
                                                }
                                                description="Transmit eligible guest folios and AR invoices to LHDN MyInvois."
                                                error={errors.enabled}
                                            />
                                            <SettingsSwitch
                                                bordered
                                                name="auto_submit_on_checkout"
                                                label="Submit upon checkout"
                                                checked={
                                                    setting?.auto_submit_on_checkout ??
                                                    false
                                                }
                                                description="Automatically submit e-invoices when a reservation folio is closed."
                                                error={
                                                    errors.auto_submit_on_checkout
                                                }
                                            />
                                            <div className="md:col-span-2">
                                                <SettingsSwitch
                                                    bordered
                                                    name="consolidation_enabled"
                                                    label="Monthly B2C consolidation"
                                                    checked={
                                                        setting?.consolidation_enabled ??
                                                        true
                                                    }
                                                    description="Aggregate unconsolidated stays under RM10,000 on the 1st of each month with General Public TIN EI00000000010."
                                                    error={
                                                        errors.consolidation_enabled
                                                    }
                                                />
                                            </div>
                                        </div>
                                    </FieldSet>

                                    <FieldSet>
                                        <FieldLegend>
                                            LHDN API Credentials
                                        </FieldLegend>
                                        <FieldGroup className="grid md:grid-cols-2">
                                            <SettingsInput
                                                name="client_id"
                                                label="Client ID"
                                                value={setting?.client_id ?? ''}
                                                placeholder="From MyInvois Taxpayer Portal"
                                                description="Generated in the MyInvois ERP integration profile."
                                                error={errors.client_id}
                                            />
                                            <SettingsInput
                                                name="client_secret"
                                                label="Client Secret"
                                                type="password"
                                                value=""
                                                placeholder={
                                                    setting?.has_client_secret
                                                        ? '•••••••• (Leave blank to keep saved secret)'
                                                        : 'Paste secret here'
                                                }
                                                description="Stored securely with AES-256 encryption."
                                                error={errors.client_secret}
                                            />
                                        </FieldGroup>
                                    </FieldSet>

                                    <FieldSet>
                                        <FieldLegend>
                                            Supplier Tax Profile
                                        </FieldLegend>
                                        <FieldGroup className="grid md:grid-cols-2">
                                            <SettingsInput
                                                name="supplier_name"
                                                label="Registered business name"
                                                value={
                                                    setting?.supplier_name ??
                                                    propertyDefaults.legal_name
                                                }
                                                description="Must exactly match your SSM registration."
                                                error={errors.supplier_name}
                                            />
                                            <SettingsInput
                                                name="supplier_tin"
                                                label="Tax Identification Number (TIN)"
                                                value={
                                                    setting?.supplier_tin ??
                                                    propertyDefaults.tin
                                                }
                                                placeholder="e.g. C1234567890"
                                                description="Your company income tax number (e.g. C or IG prefix)."
                                                error={errors.supplier_tin}
                                            />
                                            <SettingsInput
                                                name="supplier_sst_registration"
                                                label="SST registration number (optional)"
                                                value={
                                                    setting?.supplier_sst_registration ??
                                                    ''
                                                }
                                                placeholder="e.g. W10-1808-12345678"
                                                description="Required if registered for Sales and Service Tax."
                                                error={
                                                    errors.supplier_sst_registration
                                                }
                                            />
                                            <SettingsInput
                                                name="supplier_ttx_registration"
                                                label="Tourism Tax (TTx) number (optional)"
                                                value={
                                                    setting?.supplier_ttx_registration ??
                                                    ''
                                                }
                                                placeholder="e.g. TTX-12345678"
                                                description="Required if registered for Malaysian Tourism Tax."
                                                error={
                                                    errors.supplier_ttx_registration
                                                }
                                            />
                                            <SettingsInput
                                                name="supplier_msic_code"
                                                label="MSIC code"
                                                value={
                                                    setting?.supplier_msic_code ??
                                                    propertyDefaults.msic_code
                                                }
                                                description="5-digit code. Default 55101 for Hotels and resort hotels."
                                                error={
                                                    errors.supplier_msic_code
                                                }
                                            />
                                            <SettingsInput
                                                name="supplier_business_description"
                                                label="Business activity description"
                                                value={
                                                    setting?.supplier_business_description ??
                                                    propertyDefaults.business_description
                                                }
                                                description="Describes accommodation services."
                                                error={
                                                    errors.supplier_business_description
                                                }
                                            />
                                        </FieldGroup>
                                    </FieldSet>

                                    <FieldSet>
                                        <FieldLegend>
                                            Supplier Address & Contact
                                        </FieldLegend>
                                        <FieldGroup>
                                            <SettingsInput
                                                name="supplier_address_line1"
                                                label="Address line 1"
                                                value={
                                                    setting?.supplier_address_line1 ??
                                                    propertyDefaults.address_line1
                                                }
                                                error={
                                                    errors.supplier_address_line1
                                                }
                                            />
                                            <SettingsInput
                                                name="supplier_address_line2"
                                                label="Address line 2 (optional)"
                                                value={
                                                    setting?.supplier_address_line2 ??
                                                    propertyDefaults.address_line2
                                                }
                                                error={
                                                    errors.supplier_address_line2
                                                }
                                            />
                                            <FieldGroup className="grid md:grid-cols-3">
                                                <SettingsInput
                                                    name="supplier_city"
                                                    label="City"
                                                    value={
                                                        setting?.supplier_city ??
                                                        propertyDefaults.city
                                                    }
                                                    error={errors.supplier_city}
                                                />
                                                <SettingsInput
                                                    name="supplier_postal_code"
                                                    label="Postal code"
                                                    value={
                                                        setting?.supplier_postal_code ??
                                                        propertyDefaults.postal_code
                                                    }
                                                    error={
                                                        errors.supplier_postal_code
                                                    }
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
                                                    description="LHDN prescribed state code."
                                                    error={
                                                        errors.supplier_state_code
                                                    }
                                                />
                                            </FieldGroup>
                                            <FieldGroup className="grid md:grid-cols-2">
                                                <SettingsInput
                                                    name="supplier_email"
                                                    label="Finance email"
                                                    type="email"
                                                    value={
                                                        setting?.supplier_email ??
                                                        propertyDefaults.contact_email
                                                    }
                                                    error={
                                                        errors.supplier_email
                                                    }
                                                />
                                                <SettingsInput
                                                    name="supplier_phone"
                                                    label="Finance phone"
                                                    value={
                                                        setting?.supplier_phone ??
                                                        propertyDefaults.contact_phone
                                                    }
                                                    description="Formatted to E.164 (e.g. +60123456789)."
                                                    error={
                                                        errors.supplier_phone
                                                    }
                                                />
                                            </FieldGroup>
                                        </FieldGroup>
                                    </FieldSet>
                                </>
                            )}
                        </SettingsSection>
                    </div>

                    <div className="flex flex-col gap-4">
                        <Card className="h-fit">
                            <CardHeader>
                                <CardTitle>Status</CardTitle>
                                <CardDescription>
                                    Connection readiness and active mode.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <dl className="flex flex-col gap-3 text-sm">
                                    <div className="flex items-center justify-between gap-3">
                                        <dt className="text-muted-foreground">
                                            Status
                                        </dt>
                                        <dd>
                                            <StatusBadge
                                                status={{
                                                    value: setting?.enabled
                                                        ? 'active'
                                                        : 'inactive',
                                                    label: setting?.enabled
                                                        ? 'Active'
                                                        : 'Disabled',
                                                    tone: setting?.enabled
                                                        ? 'success'
                                                        : 'neutral',
                                                }}
                                            />
                                        </dd>
                                    </div>

                                    <div className="flex items-center justify-between gap-3">
                                        <dt className="text-muted-foreground">
                                            Environment
                                        </dt>
                                        <dd>
                                            <Badge
                                                variant="outline"
                                                className="capitalize"
                                            >
                                                {setting?.api_environment ??
                                                    apiEnvironment}
                                            </Badge>
                                        </dd>
                                    </div>

                                    <div className="flex items-center justify-between gap-3">
                                        <dt className="text-muted-foreground">
                                            Credentials
                                        </dt>
                                        <dd>
                                            <Badge
                                                variant={
                                                    isConfigured
                                                        ? 'outline'
                                                        : 'secondary'
                                                }
                                            >
                                                {isConfigured
                                                    ? 'Configured'
                                                    : 'Not set'}
                                            </Badge>
                                        </dd>
                                    </div>

                                    {setting?.effective_from && (
                                        <div className="flex items-center justify-between gap-3">
                                            <dt className="text-muted-foreground">
                                                Effective since
                                            </dt>
                                            <dd className="tabular-nums">
                                                {new Date(
                                                    setting.effective_from,
                                                ).toLocaleDateString('en-GB', {
                                                    day: '2-digit',
                                                    month: 'short',
                                                    year: 'numeric',
                                                })}
                                            </dd>
                                        </div>
                                    )}
                                </dl>

                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        !isConfigured || testForm.processing
                                    }
                                    focusableWhenDisabled
                                    onClick={() =>
                                        testForm.post(testRoute.url(), {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    {testForm.processing ? (
                                        <Spinner />
                                    ) : (
                                        <Plug />
                                    )}
                                    Test connection
                                </Button>

                                <Button
                                    type="button"
                                    variant="outline"
                                    render={
                                        <a
                                            href={portalUrl}
                                            target="_blank"
                                            rel="noreferrer"
                                        />
                                    }
                                >
                                    <ExternalLink />
                                    Open MyInvois Portal
                                </Button>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-sm font-semibold">
                                    <Shield className="size-4 text-primary" />
                                    Compliance Guidelines
                                </CardTitle>
                                <CardDescription>
                                    Malaysian LHDN rules for hospitality.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3 text-xs text-muted-foreground">
                                <p>
                                    • <strong>72-Hour Window:</strong> LHDN
                                    allows cancellations strictly within 72
                                    hours of validation. Any later corrections
                                    must be filed as a Credit Note (02).
                                </p>
                                <p>
                                    • <strong>B2C Consolidation:</strong> Stays
                                    where guests do not request an invoice can
                                    be consolidated monthly within 7 days of
                                    month-end with General Public TIN
                                    EI00000000010.
                                </p>
                                <p>
                                    • <strong>RM10,000 Rule:</strong> Any
                                    transaction exceeding RM10,000 cannot be
                                    consolidated and must be individually
                                    e-invoiced in real time.
                                </p>
                                <p>
                                    • <strong>Exemption:</strong> Operators with
                                    annual turnover under RM3,000,000 are exempt
                                    from mandatory issuance unless requested by
                                    a buyer.
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}

EInvoiceSettings.layout = {
    breadcrumbs: [
        { title: 'Settings', href: profile() },
        { title: 'E-invoicing', href: edit() },
    ],
};
