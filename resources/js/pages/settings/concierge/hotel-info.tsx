import { Head } from '@inertiajs/react';
import {
    ConciergeShell,
    conciergeBreadcrumbs,
} from '@/components/pms/concierge/concierge-shell';
import {
    LinkedSection,
    PanelStack,
} from '@/components/pms/concierge/guide-layout';
import {
    HotelInfoForm,
    type ParkingOptions,
} from '@/components/pms/concierge/hotel-info-form';
import { InfoTopics } from '@/components/pms/concierge/info-topics';
import { LobbyHeroCard } from '@/components/pms/concierge/lobby-hero-card';
import { profile } from '@/routes/settings';

export default function ConciergeHotelInfo({
    heroUrl,
    hasAddress,
    hotelInfo,
    parkingOptions,
    topics,
}: {
    heroUrl: string | null;
    hasAddress: boolean;
    hotelInfo: App.Data.Concierge.ConciergeHotelInfoSettingsData;
    parkingOptions: ParkingOptions;
    topics: App.Data.Concierge.ConciergeTopicData[];
}) {
    return (
        <>
            <Head title="Concierge hotel info" />
            <ConciergeShell
                activeTab="hotel-info"
                asideLabel="Photo, profile and extra topics"
                aside={
                    <PanelStack>
                        <LobbyHeroCard heroUrl={heroUrl} />
                        <LinkedSection
                            title="Property profile"
                            body="The property name, address, About text and arrival times come from the property profile."
                            ready={hasAddress}
                            href={profile.url()}
                            action="Edit property profile"
                        />
                        <InfoTopics topics={topics} />
                    </PanelStack>
                }
            >
                <HotelInfoForm
                    info={hotelInfo}
                    parkingOptions={parkingOptions}
                />
            </ConciergeShell>
        </>
    );
}

ConciergeHotelInfo.layout = () => ({
    breadcrumbs: conciergeBreadcrumbs('hotel-info'),
});
