import { Head } from '@inertiajs/react';
import {
    ConciergeShell,
    conciergeBreadcrumbs,
} from '@/components/pms/concierge/concierge-shell';
import { PanelStack } from '@/components/pms/concierge/guide-layout';
import { GuestGuidelinesForm } from '@/components/pms/concierge/guest-guidelines-form';
import { InfoTopics } from '@/components/pms/concierge/info-topics';

/** Concierge › Guest guidelines: the arrival and departure steps, then the interesting topics. No rail, so the content takes the full width. */
export default function ConciergeGuestGuidelines({
    guidelines,
    topics,
    topicLimit,
}: {
    guidelines: App.Data.Concierge.ConciergeGuestGuidelinesData;
    topics: App.Data.Concierge.ConciergeTopicData[];
    topicLimit: number;
}) {
    return (
        <>
            <Head title="Concierge guest guidelines" />
            <ConciergeShell activeTab="guest-guidelines">
                <PanelStack>
                    <GuestGuidelinesForm guidelines={guidelines} />
                    <InfoTopics topics={topics} limit={topicLimit} />
                </PanelStack>
            </ConciergeShell>
        </>
    );
}

ConciergeGuestGuidelines.layout = () => ({
    variant: 'fixed',
    breadcrumbs: conciergeBreadcrumbs('guest-guidelines'),
});
