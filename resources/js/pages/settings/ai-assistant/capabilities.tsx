import { Head } from '@inertiajs/react';
import { AiScopeSwitches } from '@/components/pms/settings/ai-assistant-scopes';
import {
    AiAssistantShell,
    aiAssistantBreadcrumbs,
} from '@/components/pms/settings/ai-assistant-tabs';

/** Settings › AI assistant › Capabilities: the switch for each capability Estatell allows. */
export default function AiAssistantCapabilities({
    settings,
}: {
    settings: App.Data.AiAssistant.AiAssistantSettingsData;
}) {
    return (
        <>
            <Head title="AI assistant" />
            <AiAssistantShell activeTab="capabilities">
                <AiScopeSwitches
                    kind="capability"
                    scopes={settings.scopes}
                    hasKey={settings.hasKey}
                />
            </AiAssistantShell>
        </>
    );
}

AiAssistantCapabilities.layout = {
    breadcrumbs: aiAssistantBreadcrumbs('capabilities'),
};
