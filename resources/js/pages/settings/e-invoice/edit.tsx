import { Head, useForm } from '@inertiajs/react';
import {
    CircleAlert,
    Cloud,
    Cpu,
    ExternalLink,
    Globe,
    Plug,
    Shield,
} from 'lucide-react';
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
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
    supplier_brn: string | null;
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

const ENVIRONMENT_LABEL: Record<string, string> = {
    mock: 'Test (offline)',
    sandbox: 'LHDN test site',
    production: 'LHDN (live)',
};

type Props = {
    setting: SettingData | null;
    /** What stops e-invoices from going out while e-invoicing is on, by field. */
    problems: Record<string, string>;
    environmentOptions: Option[];
    malaysiaStates: Option[];
    propertyDefaults: {
        tin: string | null;
        registration_number: string | null;
        brn: string;
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
    problems,
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
            <Head title="E-invoicing" />

            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="E-invoicing"
                    description="Send e-invoices to LHDN (the Malaysian tax authority) through MyInvois. Every detail below is printed on each e-invoice, so check it against your SSM and tax records."
                />

                {Object.keys(problems).length > 0 && (
                    <Alert variant="destructive">
                        <CircleAlert />
                        <AlertTitle>
                            E-invoices cannot go out until these are fixed
                        </AlertTitle>
                        <AlertDescription>
                            <ul className="flex list-disc flex-col gap-1 ps-4">
                                {Object.entries(problems).map(
                                    ([field, problem]) => (
                                        <li key={field}>{problem}</li>
                                    ),
                                )}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="flex flex-col gap-4 lg:col-span-2">
                        <SettingsSection
                            guardUnsavedChanges
                            form={EInvoiceSettingsController.update.form()}
                            title="E-invoicing"
                            description="How estatell connects to MyInvois, and the hotel details printed on every e-invoice."
                            hideHeader
                            submitLabel="Save settings"
                            contentClassName="flex flex-col gap-6"
                        >
                            {(errors) => (
                                <>
                                    <FieldSet>
                                        <FieldLegend>Connection</FieldLegend>
                                        <ChoiceCards
                                            id="api_environment"
                                            name="api_environment"
                                            legend="Send e-invoices to"
                                            columns={3}
                                            choices={[
                                                {
                                                    value: 'mock',
                                                    label: 'Test (offline)',
                                                    description:
                                                        'Nothing leaves estatell. For trying the screens.',
                                                    icon: Cpu,
                                                },
                                                {
                                                    value: 'sandbox',
                                                    label: 'LHDN test site',
                                                    description:
                                                        'LHDN’s practice copy of MyInvois. Nothing is filed.',
                                                    icon: Cloud,
                                                },
                                                {
                                                    value: 'production',
                                                    label: 'LHDN (live)',
                                                    description:
                                                        'Real e-invoices, filed with LHDN.',
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
                                                        Switching to live LHDN
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        From now on every
                                                        e-invoice is filed with
                                                        LHDN and counts for tax.
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
                                                description="Lets staff send e-invoices for stays and City ledger invoices. Needs every hotel detail below."
                                                error={errors.enabled}
                                            />
                                            <SettingsSwitch
                                                bordered
                                                name="auto_submit_on_checkout"
                                                label="Send at check-out"
                                                checked={
                                                    setting?.auto_submit_on_checkout ??
                                                    false
                                                }
                                                description="Sends the e-invoice by itself when a guest who gave a tax number checks out."
                                                error={
                                                    errors.auto_submit_on_checkout
                                                }
                                            />
                                            <div className="md:col-span-2">
                                                <SettingsSwitch
                                                    bordered
                                                    name="consolidation_enabled"
                                                    label="Monthly summary"
                                                    checked={
                                                        setting?.consolidation_enabled ??
                                                        true
                                                    }
                                                    description="On the 1st of each month, files one summary for last month's stays whose guests did not ask for their own e-invoice. Stays over RM10,000 need their own."
                                                    error={
                                                        errors.consolidation_enabled
                                                    }
                                                />
                                            </div>
                                        </div>
                                    </FieldSet>

                                    <FieldSet>
                                        <FieldLegend>
                                            MyInvois login
                                        </FieldLegend>
                                        <FieldGroup className="grid md:grid-cols-2">
                                            <SettingsInput
                                                name="client_id"
                                                label="Client ID"
                                                value={setting?.client_id ?? ''}
                                                placeholder="From the MyInvois portal"
                                                description="In the MyInvois portal, under the hotel's ERP (system) registration."
                                                error={errors.client_id}
                                            />
                                            <SettingsInput
                                                name="client_secret"
                                                label="Client secret"
                                                type="password"
                                                value=""
                                                placeholder={
                                                    setting?.has_client_secret
                                                        ? '•••••••• (Leave blank to keep saved secret)'
                                                        : 'Paste secret here'
                                                }
                                                description="Stored encrypted. Leave blank to keep the saved one."
                                                error={errors.client_secret}
                                            />
                                        </FieldGroup>
                                    </FieldSet>

                                    <FieldSet>
                                        <FieldLegend>Hotel details</FieldLegend>
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
                                                label="Tax number (TIN)"
                                                value={
                                                    setting?.supplier_tin ??
                                                    propertyDefaults.tin
                                                }
                                                placeholder="e.g. C1234567890"
                                                description="The company's LHDN tax number, starting with C (or IG for a sole owner)."
                                                error={errors.supplier_tin}
                                            />
                                            <SettingsInput
                                                name="supplier_brn"
                                                label="Company registration number (SSM)"
                                                value={
                                                    setting?.supplier_brn ??
                                                    propertyDefaults.brn
                                                }
                                                placeholder="e.g. 202001012345"
                                                description="The 12-digit number on your SSM certificate."
                                                error={errors.supplier_brn}
                                            />
                                            <SettingsInput
                                                name="supplier_sst_registration"
                                                label="SST registration number"
                                                value={
                                                    setting?.supplier_sst_registration ??
                                                    ''
                                                }
                                                placeholder="e.g. W10-1808-12345678"
                                                description="Needed when the hotel charges SST."
                                                error={
                                                    errors.supplier_sst_registration
                                                }
                                            />
                                            <SettingsInput
                                                name="supplier_ttx_registration"
                                                label="Tourism tax registration number"
                                                value={
                                                    setting?.supplier_ttx_registration ??
                                                    ''
                                                }
                                                placeholder="e.g. TTX-12345678"
                                                description="Needed when the hotel charges tourism tax."
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
                                                description="5-digit business activity code: 55101 for hotels, 55103 for homestays."
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
                                                description="Short, for example: Hotel accommodation."
                                                error={
                                                    errors.supplier_business_description
                                                }
                                            />
                                        </FieldGroup>
                                    </FieldSet>

                                    <FieldSet>
                                        <FieldLegend>
                                            Hotel address and contact
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
                                                    label="Postcode"
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
                                                    error={
                                                        errors.supplier_state_code
                                                    }
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
                                                    error={
                                                        errors.supplier_email
                                                    }
                                                />
                                                <SettingsInput
                                                    name="supplier_phone"
                                                    label="Phone"
                                                    value={
                                                        setting?.supplier_phone ??
                                                        propertyDefaults.contact_phone
                                                    }
                                                    description="With country code, for example +60123456789."
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
                                    Whether e-invoices can go out now.
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
                                                    label: !setting?.enabled
                                                        ? 'Off'
                                                        : Object.keys(problems)
                                                                .length > 0
                                                          ? 'On, details missing'
                                                          : 'On',
                                                    tone: !setting?.enabled
                                                        ? 'neutral'
                                                        : Object.keys(problems)
                                                                .length > 0
                                                          ? 'danger'
                                                          : 'success',
                                                }}
                                            />
                                        </dd>
                                    </div>

                                    <div className="flex items-center justify-between gap-3">
                                        <dt className="text-muted-foreground">
                                            Environment
                                        </dt>
                                        <dd>
                                            <Badge variant="outline">
                                                {ENVIRONMENT_LABEL[
                                                    setting?.api_environment ??
                                                        apiEnvironment
                                                ] ?? apiEnvironment}
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
                                                    ? 'Saved'
                                                    : 'Not set'}
                                            </Badge>
                                        </dd>
                                    </div>

                                    {setting?.effective_from && (
                                        <div className="flex items-center justify-between gap-3">
                                            <dt className="text-muted-foreground">
                                                On since
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
                                    Open MyInvois portal
                                </Button>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-sm font-semibold">
                                    <Shield className="size-4 text-primary" />
                                    LHDN rules in short
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3 text-xs text-muted-foreground">
                                <p>
                                    • <strong>72 hours to cancel.</strong> After
                                    that, a mistake is corrected with a credit
                                    or refund note.
                                </p>
                                <p>
                                    • <strong>Monthly summary.</strong> Stays
                                    whose guests did not ask for an e-invoice go
                                    into one summary, filed within 7 days of the
                                    month&apos;s end.
                                </p>
                                <p>
                                    • <strong>Over RM10,000.</strong> A stay
                                    over RM10,000 needs its own e-invoice with
                                    the guest&apos;s own tax number.
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
