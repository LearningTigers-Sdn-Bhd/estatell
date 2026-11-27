import { Link } from '@inertiajs/react';
import { Save, SlidersHorizontal } from 'lucide-react';
import EInvoiceSettingsController from '@/actions/App/Http/Controllers/Property/EInvoiceSettingsController';
import type { EInvoiceSetting } from '@/components/pms/einvoice/settings-types';
import { SettingsSwitch } from '@/components/pms/settings/form-fields';
import { SettingsSection } from '@/components/pms/settings-section';
import { edit as editEmail } from '@/routes/settings/emails';

/** Settings › E-invoicing › General: the three switches, and a link to the guest email wording. Saves from the floating Save bar. */
export function EInvoiceGeneralForm({
    setting,
}: {
    setting: EInvoiceSetting | null;
}) {
    return (
        <SettingsSection
            guardUnsavedChanges
            saveBar
            form={EInvoiceSettingsController.update.form()}
            title="General"
            icon={SlidersHorizontal}
            description="Whether staff can send e-invoices, and what goes out by itself."
            headerClassName="ps-2 pt-2"
            contentClassName="flex flex-col gap-5 pb-6"
            submitLabel="Save"
            submitIcon={Save}
        >
            {(errors) => (
                <>
                    <SettingsSwitch
                        name="enabled"
                        label="Enable e-invoicing"
                        checked={setting?.enabled ?? false}
                        description="On: staff can send e-invoices to LHDN. Off: nothing is sent to LHDN."
                        error={errors.enabled}
                    />
                    <SettingsSwitch
                        name="auto_submit_on_checkout"
                        label="Send at check-out"
                        checked={setting?.auto_submit_on_checkout ?? false}
                        description="On: sent by itself when a guest with a tax number checks out. Off: staff send it."
                        error={errors.auto_submit_on_checkout}
                    />
                    <SettingsSwitch
                        name="consolidation_enabled"
                        label="Monthly summary"
                        checked={setting?.consolidation_enabled ?? true}
                        description="On: other stays go into one summary on the 1st of each month. Off: no summary."
                        error={errors.consolidation_enabled}
                    />
                    <p className="text-sm text-muted-foreground">
                        To change the email guests get with their e-invoice, go
                        to{' '}
                        <Link
                            href={editEmail({ email: 'e_invoice' })}
                            className="underline underline-offset-2"
                        >
                            Settings › Emails
                        </Link>
                        .
                    </p>
                </>
            )}
        </SettingsSection>
    );
}
