import { router } from '@inertiajs/react';
import { Wifi } from 'lucide-react';
import {
    keepPage,
    RemovableItem,
    SwitchInput,
    TextField,
} from '@/components/pms/concierge/content-fields';
import { Badge } from '@/components/ui/badge';
import { ContentGrid } from '@/components/pms/concierge/guide-layout';
import {
    AddItemCard,
    ItemListCard,
} from '@/components/pms/concierge/item-forms';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { destroy, store, update } from '@/routes/settings/concierge/wifi';

/** One of the two switches of a network. It saves at once, sending the other switch as it is. */
function NetworkSwitch({
    network,
    field,
    label,
    checked,
}: {
    network: App.Data.Concierge.ConciergeWifiData;
    field: 'primary_network' | 'active';
    label: string;
    checked: boolean;
}) {
    const id = `wifi_${network.id}_${field}`;

    return (
        <span className="flex items-center gap-2">
            <Switch
                id={id}
                checked={checked}
                onCheckedChange={(next) =>
                    router.put(
                        update.url({ wifiNetwork: network.id }),
                        {
                            active: field === 'active' ? next : network.active,
                            primary_network:
                                field === 'primary_network'
                                    ? next
                                    : network.primary,
                        },
                        keepPage,
                    )
                }
            />
            <Label htmlFor={id}>{label}</Label>
        </span>
    );
}

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
                            <SwitchInput
                                name="primary_network"
                                title="Main network"
                                body="Listed first. Only one network can be the main one."
                                defaultChecked={false}
                            />
                            <SwitchInput
                                name="active"
                                title="Show to guests"
                                body="Turn off for a staff-only network."
                                defaultChecked
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
                        <span className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">{network.name}</span>
                            {network.primary && (
                                <Badge variant="secondary">Main</Badge>
                            )}
                            {!network.active && (
                                <Badge variant="outline">Staff only</Badge>
                            )}
                        </span>
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
                        <span className="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                            <NetworkSwitch
                                network={network}
                                field="primary_network"
                                label="Main network"
                                checked={network.primary}
                            />
                            <NetworkSwitch
                                network={network}
                                field="active"
                                label="Show to guests"
                                checked={network.active}
                            />
                        </span>
                    </RemovableItem>
                ))}
            </ItemListCard>
        </ContentGrid>
    );
}
