import { Wifi } from 'lucide-react';
import {
    RemovableItem,
    TextField,
} from '@/components/pms/concierge/content-fields';
import { ContentGrid } from '@/components/pms/concierge/guide-layout';
import {
    AddItemCard,
    ItemListCard,
} from '@/components/pms/concierge/item-forms';
import { destroy, store } from '@/routes/settings/concierge/wifi';

/** The Wi-Fi networks checked-in guests see, with a form to add one. */
export function WifiTab({
    networks,
}: {
    networks: App.Data.Concierge.ConciergeWifiData[];
}) {
    return (
        <ContentGrid
            aside={
                <AddItemCard
                    title="Add a network"
                    description="Share the connection details guests need during their stay."
                    action={store.url()}
                    submitLabel="Add network"
                    fields={(errors) => (
                        <>
                            <TextField
                                name="name"
                                placeholder="e.g. Hotel-Guest"
                                label="Network name"
                                error={errors.name}
                                required
                                maxLength={100}
                            />
                            <TextField
                                name="password"
                                placeholder="e.g. welcome2026"
                                label="Password (optional)"
                                error={errors.password}
                                maxLength={100}
                                autoComplete="off"
                            />
                            <TextField
                                name="note"
                                label="Note (optional)"
                                error={errors.note}
                                maxLength={200}
                                placeholder="e.g. Lobby and rooms"
                            />
                        </>
                    )}
                />
            }
        >
            <ItemListCard
                title="Guest Wi-Fi"
                description="These networks are shown only to guests who are checked in."
                count={networks.length}
                one="network"
                many="networks"
                empty={{
                    icon: Wifi,
                    title: 'No Wi-Fi networks yet',
                    description:
                        'Add a network so checked-in guests can see its name, password, and coverage note.',
                }}
            >
                {networks.map((network) => (
                    <RemovableItem
                        key={network.id}
                        url={destroy.url({ wifiNetwork: network.id })}
                        label={`Remove ${network.name}`}
                    >
                        <span className="font-medium">{network.name}</span>
                        <span className="text-muted-foreground">
                            {network.password
                                ? `Password: ${network.password}`
                                : 'No password'}
                        </span>
                        {network.note && (
                            <span className="text-pretty text-muted-foreground">
                                {network.note}
                            </span>
                        )}
                    </RemovableItem>
                ))}
            </ItemListCard>
        </ContentGrid>
    );
}
