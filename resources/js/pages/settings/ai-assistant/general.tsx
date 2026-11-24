import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { SettingsInput } from '@/components/pms/settings/form-fields';
import {
    AiAssistantShell,
    aiAssistantBreadcrumbs,
} from '@/components/pms/settings/ai-assistant-tabs';
import { SettingsSection } from '@/components/pms/settings-section';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { update as updateHandover } from '@/routes/settings/ai-assistant/handover-message';
import { destroy, update } from '@/routes/settings/ai-assistant/key';
import { update as updateCap } from '@/routes/settings/ai-assistant/monthly-cap';

/** Asks before the key is removed, because every place then stops answering. */
function RemoveKey() {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant="outline" />}>
                Remove key
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Remove the OpenAI key?</AlertDialogTitle>
                    <AlertDialogDescription>
                        The assistant stops answering everywhere until you save
                        a new key.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...destroy.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Remove key
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/**
 * Settings › AI assistant › General: the hotel's own OpenAI key, the monthly cap and the handover message. Each section
 * saves on its own. See docs/34-ai-assistant/01-overview.md#settings.
 */
export default function AiAssistantGeneral({
    settings,
    status,
}: {
    settings: App.Data.AiAssistant.AiGeneralSettingsData;
    status: App.Data.AiAssistant.AiAssistantStatusData;
}) {
    return (
        <>
            <Head title="AI assistant" />
            <AiAssistantShell activeTab="general" status={status}>
                <div className="flex flex-col gap-6 *:not-first:border-t *:not-first:pt-4">
                    <SettingsSection
                        form={update.form()}
                        title="OpenAI API key"
                        description="Your hotel pays OpenAI directly for every chat. We test the key when you save it."
                        headerClassName="ps-3 pt-2"
                        submitLabel="Save key"
                        contentClassName="grid gap-5"
                        actions={settings.hasKey ? <RemoveKey /> : undefined}
                    >
                        {(errors) => (
                            <SettingsInput
                                name="key"
                                label={settings.hasKey ? 'New key' : 'Key'}
                                type="password"
                                value=""
                                placeholder="e.g. sk-proj-4f9a…"
                                description="Stored encrypted and never shown again. Create one at platform.openai.com › API keys."
                                error={errors.key}
                            />
                        )}
                    </SettingsSection>

                    <SettingsSection
                        form={updateCap.form()}
                        title="Monthly cap"
                        description="At the cap, the assistant stops calling OpenAI and hands every chat to staff until the next month."
                        headerClassName="ps-3 pt-2"
                        submitLabel="Save cap"
                        contentClassName="grid gap-5 md:grid-cols-2"
                    >
                        {(errors) => (
                            <SettingsInput
                                name="cap_usd"
                                label="Monthly cap (US$)"
                                type="number"
                                min={0.01}
                                step="0.01"
                                value={settings.capUsd ?? ''}
                                placeholder="e.g. 20.00"
                                description="OpenAI bills in US dollars. Leave it empty for no cap."
                                error={errors.cap_usd}
                            />
                        )}
                    </SettingsSection>

                    <SettingsSection
                        form={updateHandover.form()}
                        title="Handover message"
                        description="Guests see this when the assistant cannot help and passes the chat to staff."
                        headerClassName="ps-3 pt-2"
                        submitLabel="Save message"
                        contentClassName="grid gap-5"
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
                                    defaultValue={
                                        settings.handoverMessage ?? ''
                                    }
                                    placeholder={`e.g. ${settings.defaultHandoverMessage}`}
                                    aria-invalid={!!errors.handover_message}
                                />
                                <FieldDescription>
                                    Leave it empty to use the text in the
                                    example.
                                </FieldDescription>
                                <FieldError>
                                    {errors.handover_message}
                                </FieldError>
                            </Field>
                        )}
                    </SettingsSection>
                </div>
            </AiAssistantShell>
        </>
    );
}

AiAssistantGeneral.layout = () => ({
    variant: 'fixed',
    breadcrumbs: aiAssistantBreadcrumbs('general'),
});
