import { Head } from '@inertiajs/react';
import { AiScopeSwitches } from '@/components/pms/settings/ai-assistant-scopes';
import {
    AiAssistantShell,
    aiAssistantBreadcrumbs,
} from '@/components/pms/settings/ai-assistant-tabs';

/** Settings › AI assistant › Places: the switch for each place Estatell allows. */
export default function AiAssistantPlaces({
    settings,
}: {
    settings: App.Data.AiAssistant.AiAssistantSettingsData;
}) {
    return (
        <>
            <Head title="AI assistant" />
            <AiAssistantShell activeTab="places">
                <AiScopeSwitches
                    kind="place"
                    scopes={settings.scopes}
                    hasKey={settings.hasKey}
                />
            </AiAssistantShell>
        </>
    );
}

AiAssistantPlaces.layout = {
    breadcrumbs: aiAssistantBreadcrumbs('places'),
};
