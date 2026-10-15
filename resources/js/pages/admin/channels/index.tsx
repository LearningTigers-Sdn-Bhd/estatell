import { Head, router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Lock, Plus } from 'lucide-react';
import { useState } from 'react';
import { ChannelNameDialog } from '@/components/pms/channels/channel-name-dialog';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes/admin';
import { index, reorder } from '@/routes/admin/channels';
import { update as updateActive } from '@/routes/admin/channels/active';

type Channel = App.Data.Channels.OtaChannelData;

/**
 * Swap a channel with its neighbour and send the whole order. The server checks that the ids are the full list.
 */
function moveChannel(channels: Channel[], position: number, step: -1 | 1) {
    const ids = channels.map((channel) => channel.id);
    const target = position + step;

    [ids[position], ids[target]] = [ids[target], ids[position]];

    router.put(reorder.url(), { order: ids }, { preserveScroll: true });
}

function toggleChannel(channel: Channel) {
    router.put(
        updateActive.url(channel.id),
        { active: !channel.isActive },
        { preserveScroll: true },
    );
}

export default function ChannelsIndex({ channels }: { channels: Channel[] }) {
    const [adding, setAdding] = useState(false);
    const [renaming, setRenaming] = useState<Channel | null>(null);

    return (
        <>
            <Head title="Channels" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Channels"
                    description="The online travel agencies owners can hand over logins for. Other stays on the list and asks owners for a name."
                    actions={
                        <Button onClick={() => setAdding(true)}>
                            <Plus data-icon="inline-start" />
                            Add channel
                        </Button>
                    }
                />

                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Channel</TableHead>
                                <TableHead>Code</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {channels.map((channel, position) => (
                                <TableRow key={channel.id}>
                                    <TableCell className="font-medium">
                                        <span className="flex items-center gap-2">
                                            {channel.name}
                                            {channel.isOther && (
                                                <Badge variant="secondary">
                                                    <Lock aria-hidden />
                                                    Locked
                                                </Badge>
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground tabular-nums">
                                        {channel.code}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={
                                                channel.isActive
                                                    ? {
                                                          value: 'on',
                                                          label: 'On',
                                                          tone: 'success',
                                                      }
                                                    : {
                                                          value: 'off',
                                                          label: 'Off',
                                                          tone: 'neutral',
                                                      }
                                            }
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center justify-end gap-1">
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                                aria-label={`Move ${channel.name} up`}
                                                disabled={position === 0}
                                                onClick={() =>
                                                    moveChannel(
                                                        channels,
                                                        position,
                                                        -1,
                                                    )
                                                }
                                            >
                                                <ChevronUp />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                                aria-label={`Move ${channel.name} down`}
                                                disabled={
                                                    position ===
                                                    channels.length - 1
                                                }
                                                onClick={() =>
                                                    moveChannel(
                                                        channels,
                                                        position,
                                                        1,
                                                    )
                                                }
                                            >
                                                <ChevronDown />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={channel.isOther}
                                                onClick={() =>
                                                    setRenaming(channel)
                                                }
                                            >
                                                Rename
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={channel.isOther}
                                                onClick={() =>
                                                    toggleChannel(channel)
                                                }
                                            >
                                                {channel.isActive
                                                    ? 'Turn off'
                                                    : 'Turn on'}
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <ChannelNameDialog open={adding} onOpenChange={setAdding} />
            {renaming && (
                <ChannelNameDialog
                    channel={renaming}
                    open
                    onOpenChange={(open) => !open && setRenaming(null)}
                />
            )}
        </>
    );
}

ChannelsIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Channels', href: index() },
    ],
};
