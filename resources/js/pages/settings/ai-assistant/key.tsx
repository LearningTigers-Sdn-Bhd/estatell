import { Form, Head, useForm } from '@inertiajs/react';
import { Plug } from 'lucide-react';
import { useState } from 'react';
import { SettingsInput } from '@/components/pms/settings/form-fields';
import {
    AiAssistantShell,
    aiAssistantBreadcrumbs,
} from '@/components/pms/settings/ai-assistant-tabs';
import { SettingsSection } from '@/components/pms/settings-section';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { destroy, test, update } from '@/routes/settings/ai-assistant/key';
import { update as updateCap } from '@/routes/settings/ai-assistant/monthly-cap';

/** Date and time of the last test, in the viewer's locale. */
function testedAt(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Settings › AI assistant › API key: the hotel's own OpenAI key, its status and the monthly cap. The hotel pays OpenAI
 * directly. See docs/34-ai-assistant/01-overview.md#api-key.
 */
export default function AiAssistantKey({
    settings,
}: {
    settings: App.Data.AiAssistant.AiKeySettingsData;
}) {
    const keyTest = useForm({});
    const [removeOpen, setRemoveOpen] = useState(false);
    const hasKey = settings.maskedKey !== null;

    return (
        <>
            <Head title="AI assistant" />
            <AiAssistantShell activeTab="key">
                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="flex flex-col gap-4 lg:col-span-2">
                        <SettingsSection
                            form={update.form()}
                            title="OpenAI API key"
                            description="Your hotel pays OpenAI directly for every chat. We test the key when you save it."
                            submitLabel="Save key"
                            contentClassName="grid gap-5"
                        >
                            {(errors) => (
                                <SettingsInput
                                    name="key"
                                    label={hasKey ? 'New key' : 'Key'}
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
                    </div>

                    <Card className="h-fit">
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                            <CardDescription>
                                The saved key and its last test.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <dl className="flex flex-col gap-3 text-sm">
                                <div className="flex items-center justify-between gap-3">
                                    <dt className="text-muted-foreground">
                                        Key
                                    </dt>
                                    <dd className="font-mono">
                                        {settings.maskedKey ?? '—'}
                                    </dd>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <dt className="text-muted-foreground">
                                        Status
                                    </dt>
                                    <dd>
                                        <StatusBadge status={settings.status} />
                                    </dd>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <dt className="text-muted-foreground">
                                        Last tested
                                    </dt>
                                    <dd className="tabular-nums">
                                        {settings.checkedAt
                                            ? testedAt(settings.checkedAt)
                                            : 'Never'}
                                    </dd>
                                </div>
                            </dl>

                            {settings.status.value === 'failing' && (
                                <Alert variant="destructive">
                                    <AlertDescription>
                                        OpenAI refused this key. Chats hand over
                                        to staff until you fix the key or add
                                        credit, then test it again.
                                    </AlertDescription>
                                </Alert>
                            )}

                            <Button
                                type="button"
                                variant="outline"
                                disabled={!hasKey || keyTest.processing}
                                focusableWhenDisabled
                                onClick={() =>
                                    keyTest.post(test.url(), {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                {keyTest.processing ? <Spinner /> : <Plug />}
                                Test key
                            </Button>

                            {hasKey && (
                                <AlertDialog
                                    open={removeOpen}
                                    onOpenChange={setRemoveOpen}
                                >
                                    <AlertDialogTrigger
                                        render={<Button variant="ghost" />}
                                    >
                                        Remove key
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>
                                                Remove the OpenAI key?
                                            </AlertDialogTitle>
                                            <AlertDialogDescription>
                                                The assistant stops answering
                                                everywhere until you save a new
                                                key.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <Form
                                            {...destroy.form()}
                                            options={{ preserveScroll: true }}
                                            onSuccess={() =>
                                                setRemoveOpen(false)
                                            }
                                        >
                                            {({ processing }) => (
                                                <AlertDialogFooter>
                                                    <AlertDialogCancel>
                                                        Cancel
                                                    </AlertDialogCancel>
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
                            )}
                        </CardContent>
                    </Card>
                </div>
            </AiAssistantShell>
        </>
    );
}

AiAssistantKey.layout = {
    breadcrumbs: aiAssistantBreadcrumbs('key'),
};
