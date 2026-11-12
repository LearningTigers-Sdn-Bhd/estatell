import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Ellipsis, Lock, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { ChannelNameDialog } from '@/components/pms/channels/channel-name-dialog';
import { PageHeader } from '@/components/pms/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import { toast } from '@/components/ui/toast';
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

export default function ChannelsIndex({ channels }: { channels: Channel[] }) {
    const [adding, setAdding] = useState(false);
    const [renaming, setRenaming] = useState<Channel | null>(null);
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());

    const toggleActive = (channel: Channel, active: boolean) => {
        const setToggling = (toggling: boolean) => {
            setTogglingIds((current) => {
                const next = new Set(current);

                if (toggling) {
                    next.add(channel.id);
                } else {
                    next.delete(channel.id);
                }

                return next;
            });
        };

        router
            .optimistic<{ channels: Channel[] }>((props) => ({
                channels: props.channels.map((item) =>
                    item.id === channel.id
                        ? { ...item, isActive: active }
                        : item,
                ),
            }))
            .put(
                updateActive.url(channel.id),
                { active },
                {
                    preserveScroll: true,
                    onStart: () => setToggling(true),
                    onFinish: () => setToggling(false),
                    onError: (errors) =>
                        toast.add({
                            title:
                                Object.values(errors)[0] ??
                                'The change could not be saved.',
                            type: 'error',
                        }),
                },
            );
    };

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
                                <TableHead>Available</TableHead>
                                <TableHead>Channel</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {channels.map((channel, position) => (
                                <TableRow key={channel.id}>
                                    <TableCell>
                                        <Switch
                                            size="sm"
                                            checked={channel.isActive}
                                            disabled={
                                                channel.isOther ||
                                                togglingIds.has(channel.id)
                                            }
                                            aria-label={`Make ${channel.name} ${channel.isActive ? 'unavailable' : 'available'}`}
                                            onCheckedChange={(active) =>
                                                toggleActive(channel, active)
                                            }
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex min-w-0 flex-col gap-0.5">
                                            <span className="flex items-center gap-2 font-medium">
                                                {channel.name}
                                                {channel.isOther && (
                                                    <Badge variant="secondary">
                                                        <Lock aria-hidden />
                                                        Locked
                                                    </Badge>
                                                )}
                                            </span>
                                            {channel.code && (
                                                <span className="font-mono text-xs text-muted-foreground">
                                                    {channel.code}
                                                </span>
                                            )}
                                        </div>
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
                                                <ArrowUp />
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
                                                <ArrowDown />
                                            </Button>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger
                                                    disabled={channel.isOther}
                                                    render={
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={`Actions for ${channel.name}`}
                                                        />
                                                    }
                                                >
                                                    <Ellipsis />
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            setRenaming(channel)
                                                        }
                                                    >
                                                        <Pencil />
                                                        Rename
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
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
