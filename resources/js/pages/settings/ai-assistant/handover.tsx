import { Head } from '@inertiajs/react';
import { SettingsSection } from '@/components/pms/settings-section';
import {
    AiAssistantShell,
    aiAssistantBreadcrumbs,
} from '@/components/pms/settings/ai-assistant-tabs';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/settings/ai-assistant/handover-message';

/** Settings › AI assistant › Handover: what guests see when the assistant passes the chat to staff. */
export default function AiAssistantHandover({
    settings,
}: {
    settings: App.Data.AiAssistant.AiHandoverSettingsData;
}) {
    return (
        <>
            <Head title="AI assistant" />
            <AiAssistantShell activeTab="handover">
                <SettingsSection
                    form={update.form()}
                    title="Handover message"
                    description="Guests see this when the assistant cannot help and passes the chat to staff."
                    submitLabel="Save message"
                >
                    {(errors) => (
                        <Field data-invalid={!!errors.handover_message}>
                            <FieldLabel htmlFor="handover_message">
                                Message
                            </FieldLabel>
                            <Textarea
                                id="handover_message"
                                name="handover_message"
                                rows={3}
                                maxLength={2000}
                                defaultValue={settings.handoverMessage ?? ''}
                                placeholder={`e.g. ${settings.defaultHandoverMessage}`}
                                aria-invalid={!!errors.handover_message}
                            />
                            <FieldDescription>
                                Leave it empty to use the text in the example.
                            </FieldDescription>
                            <FieldError>{errors.handover_message}</FieldError>
                        </Field>
                    )}
                </SettingsSection>
            </AiAssistantShell>
        </>
    );
}

AiAssistantHandover.layout = {
    breadcrumbs: aiAssistantBreadcrumbs('handover'),
};
