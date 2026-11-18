import { Head } from '@inertiajs/react';
import {
    ConciergeShell,
    conciergeBreadcrumbs,
} from '@/components/pms/concierge/concierge-shell';
import { AddWifiSection, WifiList } from '@/components/pms/concierge/wifi-tab';

export default function ConciergeWifi({
    wifiNetworks,
}: {
    wifiNetworks: App.Data.Concierge.ConciergeWifiData[];
}) {
    return (
        <>
            <Head title="Concierge Wi-Fi" />
            <ConciergeShell
                activeTab="wifi"
                asideLabel="Add a network"
                aside={<AddWifiSection />}
            >
                <WifiList networks={wifiNetworks} />
            </ConciergeShell>
        </>
    );
}

ConciergeWifi.layout = () => ({
    variant: 'fixed',
    breadcrumbs: conciergeBreadcrumbs('wifi'),
});
