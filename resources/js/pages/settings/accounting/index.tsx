import { Head, useForm } from '@inertiajs/react';
import { BookOpen, Calculator, Cloud, Plug } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import AccountingSettingsController from '@/actions/App/Http/Controllers/Accounting/AccountingSettingsController';
import { AccountingShell } from '@/components/pms/accounting/accounting-shell';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { DateText } from '@/components/pms/date-text';
import {
    SettingsInput,
    SettingsSwitch,
} from '@/components/pms/settings/form-fields';
import { SettingsSection } from '@/components/pms/settings-section';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { edit, test as testConnection } from '@/routes/settings/accounting';
import { profile } from '@/routes/settings';

const providerIcons: Record<string, LucideIcon> = {
    qne: Cloud,
    bukku: BookOpen,
};

/** Date and time of the last test, in the viewer's locale. */
function testedAt(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function AccountingConnection({
    setup,
    providers,
    connection,
}: {
    setup: App.Data.Accounting.AccountingSetupData;
    providers: App.Data.Accounting.AccountingProviderData[];
    connection: App.Data.Accounting.AccountingConnectionData | null;
}) {
    const [providerValue, setProviderValue] = useState(
        connection?.provider ?? providers[0]?.value ?? '',
    );
    const provider =
        providers.find((candidate) => candidate.value === providerValue) ??
        providers[0];
    /** A key saved for another provider does not carry over. */
    const keySaved =
        connection?.hasApiKey === true && connection.provider === providerValue;
    const test = useForm({});

    return (
        <>
            <Head title="Accounting" />
            <AccountingShell setup={setup} tab="connection">
                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="flex flex-col gap-4 lg:col-span-2">
                        <SettingsSection
                            guardUnsavedChanges
                            form={AccountingSettingsController.update.form()}
                            title="Connection"
                            description={provider?.keyHelp ?? ''}
                            submitLabel="Save connection"
                            contentClassName="grid gap-5 md:grid-cols-2"
                        >
                            {(errors) => (
                                <>
                                    <ChoiceCards
                                        id="provider"
                                        name="provider"
                                        legend="Accounting software"
                                        className="md:col-span-2"
                                        choices={providers.map((item) => ({
                                            value: item.value,
                                            label: item.label,
                                            description: item.description,
                                            icon:
                                                providerIcons[item.value] ??
                                                Calculator,
                                        }))}
                                        value={providerValue}
                                        onValueChange={setProviderValue}
                                        error={errors.provider}
                                    />
                                    {connection !== null &&
                                        connection.provider !==
                                            providerValue && (
                                            <Alert className="md:col-span-2">
                                                <AlertDescription>
                                                    Changing the software
                                                    removes the saved key.
                                                    Account codes differ between
                                                    software, so check the
                                                    Accounts tab afterwards.
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                    <SettingsInput
                                        key={`key-${providerValue}`}
                                        name="api_key"
                                        label={provider?.keyLabel ?? 'API key'}
                                        type="password"
                                        value=""
                                        placeholder={
                                            keySaved
                                                ? 'Saved. Leave blank to keep it.'
                                                : 'Paste it here'
                                        }
                                        description="Stored encrypted and never shown again."
                                        error={errors.api_key}
                                    />
                                    {provider?.needsCompanySubdomain && (
                                        <SettingsInput
                                            name="company_subdomain"
                                            label="Company subdomain"
                                            value={
                                                connection?.companySubdomain ??
                                                ''
                                            }
                                            placeholder="example"
                                            description="The first part of your address, for example example in example.bukku.my."
                                            error={errors.company_subdomain}
                                        />
                                    )}
                                    <SettingsInput
                                        name="guest_ledger_account_code"
                                        label="Guest ledger account"
                                        value={
                                            connection?.guestLedgerAccountCode ??
                                            ''
                                        }
                                        description="The account code that takes what guests still owe at the end of the day, so every journal balances."
                                        error={errors.guest_ledger_account_code}
                                    />
                                    <div className="grid items-stretch gap-4 md:col-span-2 md:grid-cols-2">
                                        {provider?.supportsSandbox && (
                                            <SettingsSwitch
                                                bordered
                                                name="sandbox"
                                                label="Use the test server"
                                                checked={
                                                    connection?.sandbox ?? false
                                                }
                                                description="Sends journals to the staging server. Use it with a test account, never with real books."
                                                error={errors.sandbox}
                                            />
                                        )}
                                        <SettingsSwitch
                                            bordered
                                            name="enabled"
                                            label="Push closed days automatically"
                                            checked={
                                                connection?.enabled ?? false
                                            }
                                            description="After each night audit, the closed date is pushed to your accounting software as a draft journal."
                                            error={errors.enabled}
                                        />
                                        <div className="md:col-span-2">
                                            <SettingsSwitch
                                                bordered
                                                name="push_company_billing"
                                                label="Also send company invoices and payments"
                                                checked={
                                                    connection?.pushCompanyBilling ??
                                                    false
                                                }
                                                description={`When a folio is billed to a company, its invoice, credit notes and the company's payments are sent to ${provider?.label ?? 'your accounting software'} as well, so each company's balance is kept there. Map the city ledger code to a clearing account on the Accounts tab first. Needs the daily journal on.${
                                                    connection?.companyBillingSince
                                                        ? ` Sending documents issued from ${connection.companyBillingSince}.`
                                                        : ''
                                                }`}
                                                error={
                                                    errors.push_company_billing
                                                }
                                            />
                                        </div>
                                    </div>
                                </>
                            )}
                        </SettingsSection>
                    </div>

                    <Card className="h-fit">
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                            <CardDescription>
                                What was saved and tested.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <dl className="flex flex-col gap-3 text-sm">
                                <div className="flex items-center justify-between gap-3">
                                    <dt className="text-muted-foreground">
                                        Connection
                                    </dt>
                                    <dd>
                                        <StatusBadge status={setup.status} />
                                    </dd>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <dt className="text-muted-foreground">
                                        Software
                                    </dt>
                                    <dd>{setup.providerLabel ?? '—'}</dd>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <dt className="text-muted-foreground">
                                        Last tested
                                    </dt>
                                    <dd className="tabular-nums">
                                        {setup.lastTestedAt
                                            ? testedAt(setup.lastTestedAt)
                                            : 'Never'}
                                    </dd>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <dt className="text-muted-foreground">
                                        Last journal
                                    </dt>
                                    <dd className="flex items-center gap-2">
                                        {setup.lastSync ? (
                                            <>
                                                <DateText
                                                    date={setup.lastSync.date}
                                                />
                                                <StatusBadge
                                                    status={
                                                        setup.lastSync.status
                                                    }
                                                />
                                            </>
                                        ) : (
                                            '—'
                                        )}
                                    </dd>
                                </div>
                            </dl>

                            {setup.lastError && (
                                <Alert variant="destructive">
                                    <AlertDescription>
                                        {setup.lastError}
                                    </AlertDescription>
                                </Alert>
                            )}

                            <Button
                                type="button"
                                variant="outline"
                                disabled={
                                    !setup.connectionComplete || test.processing
                                }
                                focusableWhenDisabled
                                onClick={() =>
                                    test.post(testConnection.url(), {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                {test.processing ? <Spinner /> : <Plug />}
                                Test connection
                            </Button>
                            {!setup.connectionComplete && (
                                <p className="text-xs text-muted-foreground">
                                    Save the key and the guest ledger account
                                    first. The test also checks that every
                                    mapped account exists.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </AccountingShell>
        </>
    );
}

AccountingConnection.layout = {
    breadcrumbs: [
        { title: 'Settings', href: profile() },
        { title: 'Accounting', href: edit() },
    ],
};
