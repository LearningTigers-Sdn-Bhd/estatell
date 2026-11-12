import { Head } from '@inertiajs/react';
import { ExternalLink, Printer } from 'lucide-react';
import { AmenitiesTab } from '@/components/pms/concierge/amenities-tab';
import { ContactsForm } from '@/components/pms/concierge/contacts-form';
import { FaqsTab } from '@/components/pms/concierge/faqs-tab';
import {
    ContentGrid,
    LinkedSection,
} from '@/components/pms/concierge/guide-layout';
import {
    CONCIERGE_TABS,
    guideReadiness,
    type Sections,
} from '@/components/pms/concierge/guide-sections';
import { HotelInfoForm } from '@/components/pms/concierge/hotel-info-form';
import { InfoTopics } from '@/components/pms/concierge/info-topics';
import { NearbyTab } from '@/components/pms/concierge/nearby-tab';
import { LobbyPrintCard } from '@/components/pms/concierge/lobby-qr';
import { OverviewTab } from '@/components/pms/concierge/overview-tab';
import { PoliciesTab } from '@/components/pms/concierge/policies-tab';
import { ConciergeTabList } from '@/components/pms/concierge/tab-list';
import { WifiTab } from '@/components/pms/concierge/wifi-tab';
import { PageHeader } from '@/components/pms/page-header';
import { Button, buttonVariants } from '@/components/ui/button';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import { useTabParam } from '@/hooks/use-tab-param';
import { profile } from '@/routes/settings';
import { index } from '@/routes/settings/concierge';

const TAB_KEYS = CONCIERGE_TABS.map((tab) => tab.key);

/** Staff side of the Concierge page: the lobby QR and switches, and each section of the guide guests read. */
export default function ConciergeSettings({
    lobbyUrl,
    lobbyQr,
    walkInEnabled,
    refundRequestsEnabled,
    houseRules,
    wifiNetworks,
    faqs,
    policyTerms,
    sections,
    nearbyPlaces,
    suggestions,
    hotelInfo,
    contacts,
    topics,
    amenities,
    amenityDetails,
    page,
    listUrl,
}: {
    lobbyUrl: string;
    lobbyQr: string;
    walkInEnabled: boolean;
    refundRequestsEnabled: boolean;
    houseRules: string | null;
    wifiNetworks: App.Data.Concierge.ConciergeWifiData[];
    faqs: App.Data.Concierge.ConciergeFaqData[];
    policyTerms: App.Data.Concierge.ConciergePolicyTermData[];
    sections: Sections;
    nearbyPlaces: App.Data.BookingEngine.PropertyNearbyPlaceData[];
    suggestions: App.Data.BookingEngine.NearbyPlaceSuggestionData[];
    hotelInfo: App.Data.Concierge.ConciergeHotelInfoSettingsData;
    contacts: App.Data.Concierge.ConciergeContactSettingsData;
    topics: App.Data.Concierge.ConciergeTopicData[];
    amenities: App.Data.BookingEngine.AmenityPickerData;
    amenityDetails: App.Data.Concierge.ConciergeAmenitySettingsData[];
    page: number;
    listUrl: string;
}) {
    const [tab, openTab] = useTabParam(TAB_KEYS);

    const readiness = guideReadiness({
        sections,
        houseRules,
        faqCount: faqs.length,
        wifiCount: wifiNetworks.length,
    });

    return (
        <>
            <Head title="Concierge" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="print:hidden">
                    <PageHeader
                        title="Concierge"
                        description="Set up the guide guests read before and during their stay, and the QR code they scan in the lobby."
                        actions={
                            <>
                                <a
                                    href={lobbyUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className={buttonVariants({
                                        variant: 'outline',
                                        className: 'min-h-11',
                                    })}
                                >
                                    <ExternalLink data-icon="inline-start" />
                                    View lobby
                                </a>
                                <Button
                                    type="button"
                                    className="min-h-11"
                                    onClick={() => window.print()}
                                >
                                    <Printer data-icon="inline-start" />
                                    Print lobby QR
                                </Button>
                            </>
                        }
                    />
                </div>

                <Tabs
                    value={tab}
                    onValueChange={openTab}
                    className="min-w-0 gap-4 print:hidden"
                >
                    <ConciergeTabList active={tab} />

                    <TabsContent value="overview" className="pt-2">
                        <OverviewTab
                            items={readiness}
                            lobbyUrl={lobbyUrl}
                            lobbyQr={lobbyQr}
                            houseRules={houseRules}
                            walkInEnabled={walkInEnabled}
                            refundRequestsEnabled={refundRequestsEnabled}
                            onOpen={openTab}
                        />
                    </TabsContent>

                    <TabsContent value="hotel-info" className="pt-2">
                        <ContentGrid
                            aside={
                                <>
                                    <LinkedSection
                                        title="Property profile"
                                        body="The property name, address, and arrival times come from the property profile."
                                        ready={sections.hasAddress}
                                        href={profile.url()}
                                        action="Edit property profile"
                                    />
                                    <InfoTopics topics={topics} />
                                </>
                            }
                        >
                            <HotelInfoForm info={hotelInfo} />
                        </ContentGrid>
                    </TabsContent>

                    <TabsContent value="policies" className="pt-2">
                        <PoliciesTab
                            houseRules={houseRules}
                            policyTerms={policyTerms}
                            walkInEnabled={walkInEnabled}
                            refundRequestsEnabled={refundRequestsEnabled}
                        />
                    </TabsContent>

                    <TabsContent value="faqs" className="pt-2">
                        <FaqsTab faqs={faqs} />
                    </TabsContent>

                    <TabsContent value="amenities" className="pt-2">
                        <AmenitiesTab
                            picker={amenities}
                            page={page}
                            listUrl={listUrl}
                            details={amenityDetails}
                        />
                    </TabsContent>

                    <TabsContent value="nearby" className="pt-2">
                        <NearbyTab
                            places={nearbyPlaces}
                            suggestions={suggestions}
                        />
                    </TabsContent>

                    <TabsContent value="wifi" className="pt-2">
                        <WifiTab networks={wifiNetworks} />
                    </TabsContent>

                    <TabsContent value="contact" className="pt-2">
                        <ContentGrid
                            aside={
                                <LinkedSection
                                    title="Phone and email"
                                    body="The main phone number and email on the Contact page come from the property profile."
                                    ready={sections.hasContact}
                                    href={profile.url()}
                                    action="Edit property profile"
                                />
                            }
                        >
                            <ContactsForm contacts={contacts} />
                        </ContentGrid>
                    </TabsContent>
                </Tabs>

                <LobbyPrintCard url={lobbyUrl} qr={lobbyQr} />
            </div>
        </>
    );
}

ConciergeSettings.layout = {
    breadcrumbs: [
        { title: 'Settings', href: profile() },
        { title: 'Concierge', href: index() },
    ],
};
