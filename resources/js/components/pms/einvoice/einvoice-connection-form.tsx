import { Cloud, Cpu, Globe, Plug, Save, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import EInvoiceSettingsController from '@/actions/App/Http/Controllers/Property/EInvoiceSettingsController';
import { ChoiceCards } from '@/components/pms/choice-cards';
import type { EInvoiceSetting } from '@/components/pms/einvoice/settings-types';
import { SettingsInput } from '@/components/pms/settings/form-fields';
import { SettingsSection } from '@/components/pms/settings-section';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';

/**
 * Settings › E-invoicing › Connection: where e-invoices go and the MyInvois login. Saves from the floating Save bar.
 * The picked environment lives inside the section's fields, so Discard (which remounts the fields) also resets it.
 */
export function EInvoiceConnectionForm({
    setting,
}: {
    setting: EInvoiceSetting | null;
}) {
    return (
        <SettingsSection
            guardUnsavedChanges
            saveBar
            form={EInvoiceSettingsController.updateConnection.form()}
            title="Connection"
            icon={Plug}
            description="How estatell reaches MyInvois, LHDN's e-invoice system."
            headerClassName="ps-2 pt-2"
            contentClassName="flex flex-col gap-6 pb-6"
            submitLabel="Save"
            submitIcon={Save}
        >
            {(errors) => <ConnectionFields setting={setting} errors={errors} />}
        </SettingsSection>
    );
}

function ConnectionFields({
    setting,
    errors,
}: {
    setting: EInvoiceSetting | null;
    errors: Record<string, string>;
}) {
    const [apiEnvironment, setApiEnvironment] = useState(
        setting?.api_environment ?? 'sandbox',
    );
    const switchingToLive =
        apiEnvironment === 'production' &&
        setting?.api_environment !== 'production';

    return (
        <>
            <div className="flex flex-col gap-4">
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
                            description: 'Real e-invoices, filed with LHDN.',
                            icon: Globe,
                        },
                    ]}
                    value={apiEnvironment}
                    onValueChange={setApiEnvironment}
                    error={errors.api_environment}
                />
                {switchingToLive && (
                    <Alert>
                        <TriangleAlert className="text-warning" />
                        <AlertTitle>Switching to live LHDN</AlertTitle>
                        <AlertDescription className="flex flex-col gap-3">
                            <p>
                                From now on every e-invoice is filed with LHDN
                                and counts for tax.
                            </p>
                            <Field
                                orientation="horizontal"
                                className="items-start"
                                data-invalid={!!errors.confirm_production}
                            >
                                <Checkbox
                                    id="confirm_production"
                                    name="confirm_production"
                                    value="1"
                                    aria-invalid={!!errors.confirm_production}
                                    className="mt-0.5"
                                />
                                <div className="flex flex-col gap-1">
                                    <FieldLabel htmlFor="confirm_production">
                                        I understand this property will submit
                                        real, legally binding tax documents to
                                        LHDN.
                                    </FieldLabel>
                                    <FieldError>
                                        {errors.confirm_production}
                                    </FieldError>
                                </div>
                            </Field>
                        </AlertDescription>
                    </Alert>
                )}
            </div>

            <FieldSet>
                <FieldLegend>MyInvois login</FieldLegend>
                <FieldGroup className="grid md:grid-cols-2">
                    <SettingsInput
                        name="client_id"
                        label="Client ID"
                        value={setting?.client_id ?? ''}
                        placeholder="e.g. 8f2c1a9e-4b7d-4e2a-9c1f-3d5e6a7b8c9d"
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
                                ? '•••••••• (saved)'
                                : 'e.g. paste the secret from MyInvois'
                        }
                        description="Stored encrypted. Leave blank to keep the saved one."
                        error={errors.client_secret}
                    />
                </FieldGroup>
            </FieldSet>
        </>
    );
}
