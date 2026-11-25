import { Form, Head } from '@inertiajs/react';
import { Gauge, KeyRound, MessageSquareShare } from 'lucide-react';
import { useState } from 'react';
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
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
    InputGroupText,
    InputGroupTextarea,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { update as updateHandover } from '@/routes/settings/ai-assistant/handover-message';
import { destroy, update } from '@/routes/settings/ai-assistant/key';
import { update as updateCap } from '@/routes/settings/ai-assistant/monthly-cap';

/** The submit button inside a single input or text box. */
function SaveButton({
    label,
    processing,
}: {
    label: string;
    processing: boolean;
}) {
    return (
        <InputGroupButton
            type="submit"
            variant="default"
            disabled={processing}
            focusableWhenDisabled
        >
            {processing && <Spinner data-icon="inline-start" />}
            {label}
        </InputGroupButton>
    );
}

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
 * saves on its own. See docs/35-ai-assistant/01-overview.md#settings.
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
                        icon={KeyRound}
                        description="Your hotel pays OpenAI directly for every chat. We test the key when you save it."
                        headerClassName="ps-2 pt-2"
                        submitLabel="Save key"
                        submitInField
                        contentClassName="grid gap-5"
                        actions={settings.hasKey ? <RemoveKey /> : undefined}
                    >
                        {(errors, { processing }) => (
                            <Field data-invalid={!!errors.key}>
                                <FieldLabel htmlFor="key">
                                    {settings.hasKey ? 'New key' : 'Key'}
                                </FieldLabel>
                                <InputGroup>
                                    <InputGroupInput
                                        id="key"
                                        name="key"
                                        type="password"
                                        autoComplete="off"
                                        placeholder="e.g. sk-proj-4f9a…"
                                        aria-invalid={!!errors.key}
                                    />
                                    <InputGroupAddon align="inline-end">
                                        <SaveButton
                                            label="Save key"
                                            processing={processing}
                                        />
                                    </InputGroupAddon>
                                </InputGroup>
                                <FieldDescription>
                                    Stored encrypted and never shown again.
                                    Create one at platform.openai.com › API
                                    keys.
                                </FieldDescription>
                                <FieldError>{errors.key}</FieldError>
                            </Field>
                        )}
                    </SettingsSection>

                    <SettingsSection
                        form={updateCap.form()}
                        title="Monthly cap"
                        icon={Gauge}
                        description="At the cap, the assistant stops calling OpenAI and hands every chat to staff until the next month."
                        headerClassName="ps-2 pt-2"
                        submitLabel="Save cap"
                        submitInField
                        contentClassName="grid gap-5 md:grid-cols-2"
                    >
                        {(errors, { processing }) => (
                            <Field data-invalid={!!errors.cap_usd}>
                                <FieldLabel htmlFor="cap_usd">
                                    Monthly cap
                                </FieldLabel>
                                <InputGroup>
                                    <InputGroupAddon>
                                        <InputGroupText>US$</InputGroupText>
                                    </InputGroupAddon>
                                    <InputGroupInput
                                        id="cap_usd"
                                        name="cap_usd"
                                        type="number"
                                        min={0.01}
                                        step="0.01"
                                        defaultValue={settings.capUsd ?? ''}
                                        placeholder="e.g. 20.00"
                                        aria-invalid={!!errors.cap_usd}
                                    />
                                    <InputGroupAddon align="inline-end">
                                        <SaveButton
                                            label="Save cap"
                                            processing={processing}
                                        />
                                    </InputGroupAddon>
                                </InputGroup>
                                <FieldDescription>
                                    OpenAI bills in US dollars. Leave it empty
                                    for no cap.
                                </FieldDescription>
                                <FieldError>{errors.cap_usd}</FieldError>
                            </Field>
                        )}
                    </SettingsSection>

                    <SettingsSection
                        form={updateHandover.form()}
                        title="Handover message"
                        icon={MessageSquareShare}
                        description="Guests see this when the assistant cannot help and passes the chat to staff."
                        headerClassName="ps-2 pt-2"
                        submitLabel="Save message"
                        submitInField
                        contentClassName="grid gap-5"
                    >
                        {(errors, { processing }) => (
                            <Field data-invalid={!!errors.handover_message}>
                                <FieldLabel htmlFor="handover_message">
                                    Message
                                </FieldLabel>
                                <InputGroup>
                                    <InputGroupTextarea
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
                                    <InputGroupAddon
                                        align="block-end"
                                        className="justify-end"
                                    >
                                        <SaveButton
                                            label="Save message"
                                            processing={processing}
                                        />
                                    </InputGroupAddon>
                                </InputGroup>
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
