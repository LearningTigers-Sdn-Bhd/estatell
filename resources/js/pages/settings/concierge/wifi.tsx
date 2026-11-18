import { Head } from '@inertiajs/react';
import {
    ConciergeShell,
    conciergeBreadcrumbs,
} from '@/components/pms/concierge/concierge-shell';
import { WifiNetworks } from '@/components/pms/concierge/wifi-tab';

/** Concierge › Wi-Fi: the networks in a table. No rail, so the table takes the full width. */
export default function ConciergeWifi({
    wifiNetworks,
    wifiLimit,
}: {
    wifiNetworks: App.Data.Concierge.ConciergeWifiData[];
    wifiLimit: number;
}) {
    return (
        <>
            <Head title="Concierge Wi-Fi" />
            <ConciergeShell activeTab="wifi">
                <WifiNetworks networks={wifiNetworks} limit={wifiLimit} />
            </ConciergeShell>
        </>
    );
}

ConciergeWifi.layout = () => ({
    variant: 'fixed',
    breadcrumbs: conciergeBreadcrumbs('wifi'),
});
