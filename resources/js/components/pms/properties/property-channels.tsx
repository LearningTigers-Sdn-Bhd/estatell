import { Form, router, usePage, usePoll } from '@inertiajs/react';
import {
    ArrowRight,
    Cable,
    CalendarRange,
    ChevronRight,
    History,
    Layers,
    RefreshCw,
    RotateCcw,
    Store,
    Trash2,
    TriangleAlert,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ChannelOtasTable } from '@/components/pms/channels/channel-otas-card';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    Alert,
    AlertAction,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { toast } from '@/components/ui/toast';
import { cn } from '@/lib/utils';
import { fullRefresh, sync } from '@/routes/admin/properties/channels';
import { resolve as resolveAlert } from '@/routes/admin/properties/channels/alerts';
import { destroy as destroyRatePlan } from '@/routes/admin/properties/channels/rate-plans';
import { retry as retryRevision } from '@/routes/admin/properties/channels/revisions';
import { destroy as destroyRoomType } from '@/routes/admin/properties/channels/room-types';

type Channel = App.Data.Channels.ChannelTabData;
type SyncedRoomType = App.Data.Channels.ChannelSyncedRoomTypeData;
type Revision = App.Data.Channels.ChannelRevisionData;
type SyncProgress = App.Data.Channels.ChannelSyncProgressData;
type ChannelAlert = App.Data.Channels.ChannelAlertData;

export type ChannelSection = 'channex' | 'alerts' | 'on-channex' | 'bookings';

const sections: {
    value: ChannelSection;
    label: string;
    icon: LucideIcon;
}[] = [
    { value: 'channex', label: 'Channex and OTAs', icon: Cable },
    { value: 'alerts', label: 'Alerts', icon: TriangleAlert },
    { value: 'on-channex', label: 'On Channex', icon: Layers },
    { value: 'bookings', label: 'Bookings', icon: CalendarRange },
];

/**
 * The open section of the Channels tab, kept in `?section=` so a refresh keeps it. Switching makes no request: every
 * section's data is already on the page. An unknown value opens the first section.
 */
export function useChannelSection(): [
    ChannelSection,
    (next: ChannelSection) => void,
] {
    const { url } = usePage();
    const current = new URL(url, 'http://localhost');
    const value = current.searchParams.get('section');
    const section =
        sections.find((option) => option.value === value)?.value ?? 'channex';

    const setSection = (next: ChannelSection) => {
        const target = new URL(url, 'http://localhost');

        if (next === 'channex') {
            target.searchParams.delete('section');
        } else {
            target.searchParams.set('section', next);
        }

        router.replace({
            url: `${target.pathname}${target.search}`,
            preserveScroll: true,
            preserveState: true,
        });
    };

    return [section, setSection];
}

/** The open alert count: bookings that were not saved plus open Channex alerts. */
export function channelAlertCount(channel: Channel): number {
    return channel.failedBookings.length + channel.alerts.length;
}

/**
 * The Channels tab's section list, in the Workspace list panel. Below `lg` it is a select, like the reservation unit list.
 */
export function ChannelSectionList({
    channel,
    section,
    onSectionChange,
}: {
    channel: Channel;
    section: ChannelSection;
    onSectionChange: (next: ChannelSection) => void;
}) {
    const alertCount = channelAlertCount(channel);

    return (
        <>
            <div className="lg:hidden">
                <EnumSelect
                    aria-label="Choose section"
                    options={sections.map((option) => ({
                        value: option.value,
                        label:
                            option.value === 'alerts' && alertCount > 0
                                ? `${option.label} (${alertCount})`
                                : option.label,
                    }))}
                    value={section}
                    onValueChange={(value) =>
                        onSectionChange(value as ChannelSection)
                    }
                />
            </div>
            <nav
                aria-label="Choose section"
                className="hidden flex-col gap-1 lg:flex"
            >
                {sections.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        aria-current={
                            option.value === section ? 'true' : undefined
                        }
                        onClick={() => onSectionChange(option.value)}
                        className={cn(
                            'flex min-w-0 items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm text-foreground hover:bg-muted',
                            option.value === section &&
                                'border-primary bg-muted',
                        )}
                    >
                        <option.icon
                            aria-hidden
                            className="size-4 shrink-0 text-muted-foreground"
                        />
                        <span className="min-w-0 flex-1 truncate font-medium">
                            {option.label}
                        </span>
                        {option.value === 'alerts' && alertCount > 0 && (
                            <StatusBadge
                                dot={false}
                                status={{
                                    value: 'alerts',
                                    label: String(alertCount),
                                    tone: 'danger',
                                }}
                            />
                        )}
                    </button>
                ))}
            </nav>
        </>
    );
}

/**
 * A section heading in the Workspace panel: an icon box, the title and description, and actions on the right. The icon
 * box sets it apart; `ps-2` lines the box up with the text of the tables below.
 */
function SectionHeading({
    icon: Icon,
    title,
    description,
    actions,
}: {
    icon: LucideIcon;
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3 ps-2 pt-2">
            <div className="flex min-w-0 items-start gap-3">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <Icon aria-hidden className="size-4" />
                </span>
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h2 className="flex items-center gap-2 text-base font-medium">
                        {title}
                    </h2>
                    {description && (
                        <div className="text-sm text-muted-foreground">
                            {description}
                        </div>
                    )}
                </div>
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}

/**
 * The admin Channels tab body. One section shows at a time (see `ChannelSectionList`). The first, Channex and OTAs,
 * holds the sync status and the OTA table, where most admin work happens. Before the first sync only it has content.
 */
export function PropertyChannels({
    code,
    channel,
    section,
    onSectionChange,
}: {
    code: string;
    channel: Channel;
    section: ChannelSection;
    onSectionChange: (next: ChannelSection) => void;
}) {
    const notSynced = channel.status.value === 'not_synced';
    const syncing = useSyncProgressToast(channel.sync);
    const alertCount = channelAlertCount(channel);

    if (section !== 'channex' && notSynced) {
        return (
            <EmptyState
                icon={Cable}
                title="Sync to Channex first"
                description="This section fills in after the property's room types and rate plans are on Channex."
            />
        );
    }

    if (section === 'alerts') {
        return (
            <Alerts
                code={code}
                failed={channel.failedBookings}
                alerts={channel.alerts}
            />
        );
    }

    if (section === 'on-channex') {
        return <SyncedItems code={code} items={channel.synced} />;
    }

    if (section === 'bookings') {
        return <RecentBookings items={channel.recentBookings} />;
    }

    return (
        <div className="flex flex-col gap-4">
            <SectionHeading
                icon={Cable}
                title={
                    <>
                        Channex
                        <StatusBadge status={channel.status} />
                    </>
                }
                description={
                    notSynced ? (
                        "Send this property's room types and rate plans to Channex."
                    ) : (
                        <SyncedLines channel={channel} />
                    )
                }
                actions={
                    <>
                        {channel.canFullRefresh && <FullRefresh code={code} />}
                        <SyncPreview
                            code={code}
                            preview={channel.preview}
                            label={notSynced ? 'Preview sync' : 'Sync again'}
                            disabled={syncing}
                        />
                    </>
                }
            />
            {syncing && (
                <Progress
                    value={
                        channel.sync.total > 0
                            ? (channel.sync.done / channel.sync.total) * 100
                            : null
                    }
                >
                    <span className="text-sm text-muted-foreground">
                        {channel.sync.total > 0
                            ? `Syncing to Channex: ${channel.sync.done} of ${channel.sync.total} steps. You can leave this page.`
                            : 'Waiting to start. You can leave this page.'}
                    </span>
                </Progress>
            )}
            {channel.sync.waiting && !syncing && (
                <Alert>
                    <AlertTitle>Changes are waiting to sync</AlertTitle>
                    <AlertDescription>
                        Staff changed rooms or rates. They are sent to Channex
                        in about 2 minutes, after the last change.
                    </AlertDescription>
                </Alert>
            )}
            {channel.pausedUntil && (
                <Alert>
                    <AlertTitle>
                        Paused until{' '}
                        {new Date(channel.pausedUntil).toLocaleTimeString(
                            'en-GB',
                        )}
                    </AlertTitle>
                    <AlertDescription>
                        Channex asked us to wait. {channel.waitingChanges}{' '}
                        changes waiting, none lost.
                    </AlertDescription>
                </Alert>
            )}
            {channel.lastError && (
                <Alert variant="destructive">
                    <AlertTitle>The last sync did not finish</AlertTitle>
                    <AlertDescription>{channel.lastError}</AlertDescription>
                </Alert>
            )}
            {!notSynced && alertCount > 0 && (
                <Alert variant="destructive" className="pr-32">
                    <TriangleAlert />
                    <AlertTitle>
                        {alertCount === 1
                            ? '1 alert needs action'
                            : `${alertCount} alerts need action`}
                    </AlertTitle>
                    <AlertDescription>
                        A booking that is not saved is not acknowledged to
                        Channex.
                    </AlertDescription>
                    <AlertAction className="top-1/2 -translate-y-1/2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => onSectionChange('alerts')}
                        >
                            <ArrowRight data-icon="inline-start" />
                            View alerts
                        </Button>
                    </AlertAction>
                </Alert>
            )}

            <section aria-label="OTAs" className="flex flex-col gap-4 pt-2">
                <SectionHeading
                    icon={Store}
                    title="OTAs"
                    description="Connect each OTA the property sells on. Channex then sends availability and rates there and brings bookings back."
                />
                {notSynced ? (
                    <p className="ps-13 text-sm text-muted-foreground">
                        Sync to Channex first.
                    </p>
                ) : (
                    <ChannelOtasTable code={code} otas={channel.otas} />
                )}
            </section>
        </div>
    );
}

const shortDate = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

/** The sync status in two short lines: what is on Channex, then when changes and the last full refresh went. */
function SyncedLines({ channel }: { channel: Channel }) {
    const { roomTypes, ratePlans, skipped } = channel.preview;
    const first = [
        channel.structureSyncedAt
            ? `Synced ${shortDate.format(new Date(channel.structureSyncedAt))}`
            : 'Structure not synced yet',
        `${roomTypes} room types`,
        `${ratePlans} rate plans`,
        skipped > 0 ? `${skipped} skipped` : null,
    ].filter(Boolean);
    const second = [
        channel.changesSentAt
            ? `Changes sent ${shortDate.format(new Date(channel.changesSentAt))}`
            : null,
        channel.fullSyncedAt
            ? `Full refresh ${shortDate.format(new Date(channel.fullSyncedAt))}`
            : null,
    ].filter(Boolean);

    return (
        <>
            <p>{first.join(' · ')}</p>
            {second.length > 0 && (
                <p className="text-xs">{second.join(' · ')}</p>
            )}
        </>
    );
}

function FullRefresh({ code }: { code: string }) {
    return (
        <Form {...fullRefresh.form(code)} options={{ preserveScroll: true }}>
            {({ processing }) => (
                <Button
                    type="submit"
                    variant="outline"
                    disabled={processing}
                    focusableWhenDisabled
                >
                    {processing ? (
                        <Spinner data-icon="inline-start" />
                    ) : (
                        <History data-icon="inline-start" />
                    )}
                    Full refresh (500 days)
                </Button>
            )}
        </Form>
    );
}

/**
 * The structure sync runs in the background. While it waits or runs, the tab polls its progress and keeps one toast up
 * to date, then turns it into the result. Returns whether a sync is active. A sync that finished before this page
 * loaded shows no toast: the card already shows the result.
 */
function useSyncProgressToast(sync: SyncProgress): boolean {
    const toastId = useRef<string | null>(null);
    const active = sync.state === 'queued' || sync.state === 'running';
    const { start, stop } = usePoll(
        2000,
        { only: ['channel'] },
        { autoStart: false, mode: 'rest' },
    );

    useEffect(() => {
        if (active) {
            start();
        } else {
            stop();
        }
    }, [active, start, stop]);

    useEffect(() => {
        const description =
            sync.total > 0
                ? `${sync.done} of ${sync.total} steps`
                : 'Waiting to start';

        if (active) {
            if (toastId.current === null) {
                toastId.current = toast.add({
                    title: 'Syncing to Channex',
                    description,
                    type: 'loading',
                    timeout: 0,
                });
            } else {
                toast.update(toastId.current, { description });
            }

            return;
        }

        if (toastId.current === null) {
            return;
        }

        if (sync.state === 'idle') {
            toast.close(toastId.current);
        } else {
            const failed = sync.state === 'failed';

            toast.update(toastId.current, {
                title: failed ? 'Sync to Channex failed' : 'Synced to Channex',
                description: sync.message ?? undefined,
                type: failed ? 'error' : 'success',
                timeout: 8000,
            });
        }

        toastId.current = null;
    }, [active, sync.done, sync.total, sync.state, sync.message]);

    useEffect(
        () => () => {
            if (toastId.current !== null) {
                toast.close(toastId.current);
            }
        },
        [],
    );

    return active;
}

function SyncPreview({
    code,
    preview,
    label,
    disabled,
}: {
    code: string;
    preview: App.Data.Channels.ChannelStructurePreviewData;
    label: string;
    disabled: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button disabled={disabled} />}>
                <RefreshCw data-icon="inline-start" />
                {label}
            </DialogTrigger>
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Sync to Channex</DialogTitle>
                    <DialogDescription>
                        {preview.roomTypes} room types · {preview.ratePlans}{' '}
                        rate plans to send · {preview.skipped} skipped. Running
                        it again updates the same records.
                    </DialogDescription>
                </DialogHeader>

                {preview.blockedReason && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            {preview.blockedReason}
                        </AlertDescription>
                    </Alert>
                )}

                <div className="max-h-96 overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Room type</TableHead>
                                <TableHead>Rate plan</TableHead>
                                <TableHead>Sell mode</TableHead>
                                <TableHead>Result</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {preview.rows.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="py-8 text-center text-muted-foreground"
                                    >
                                        No room types with rate plans yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {preview.rows.map((row) => (
                                <TableRow
                                    key={`${row.roomType}-${row.ratePlan}`}
                                >
                                    <TableCell className="font-medium">
                                        {row.roomType}
                                    </TableCell>
                                    <TableCell>{row.ratePlan}</TableCell>
                                    <TableCell>{row.sellMode}</TableCell>
                                    <TableCell>
                                        <div className="flex flex-col items-start gap-1">
                                            <StatusBadge status={row.support} />
                                            {row.detail && (
                                                <span className="text-xs text-muted-foreground">
                                                    {row.detail}
                                                </span>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Form
                    {...sync.form(code)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <DialogFooter>
                            <DialogClose render={<Button variant="outline" />}>
                                Cancel
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={
                                    processing || preview.blockedReason !== null
                                }
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Sync to Channex
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function SyncedItems({
    code,
    items,
}: {
    code: string;
    items: SyncedRoomType[];
}) {
    const [openIds, setOpenIds] = useState<Set<number>>(new Set());
    const toggle = (id: number) =>
        setOpenIds((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    return (
        <div className="flex flex-col gap-4">
            <SectionHeading
                icon={Layers}
                title="On Channex"
                description="When staff archive a room type or rate plan, it is removed here first, automatically. Remove an item by hand only if that did not work. Channex refuses a removal while an OTA still uses the item."
            />
            {items.length === 0 ? (
                <p className="ps-13 text-sm text-muted-foreground">
                    Nothing is on Channex.
                </p>
            ) : (
                <div className="overflow-x-auto rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Room type and rate plans</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.map((item) => (
                                <Fragment key={item.mappingId}>
                                    <TableRow className="bg-muted/50 hover:bg-muted/50">
                                        <TableCell className="p-0">
                                            <button
                                                type="button"
                                                aria-expanded={openIds.has(
                                                    item.mappingId,
                                                )}
                                                onClick={() =>
                                                    toggle(item.mappingId)
                                                }
                                                className="flex w-full items-center gap-2 p-2 text-left font-medium"
                                            >
                                                <ChevronRight
                                                    aria-hidden
                                                    className={cn(
                                                        'size-4 text-muted-foreground transition-transform',
                                                        openIds.has(
                                                            item.mappingId,
                                                        ) && 'rotate-90',
                                                    )}
                                                />
                                                {item.name}
                                                <span className="font-normal text-muted-foreground tabular-nums">
                                                    (
                                                    {item.ratePlans.length === 1
                                                        ? '1 rate plan'
                                                        : `${item.ratePlans.length} rate plans`}
                                                    )
                                                </span>
                                            </button>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <RemoveMenu
                                                label={`Remove options for ${item.name}`}
                                                items={[
                                                    {
                                                        text: 'Remove room type',
                                                        title: `Remove ${item.name} from Channex?`,
                                                        description:
                                                            'Remove its rate plans first. Channex refuses while an OTA uses it.',
                                                        action: destroyRoomType.form(
                                                            {
                                                                property: code,
                                                                roomTypeMapping:
                                                                    item.mappingId,
                                                            },
                                                        ),
                                                        disabled:
                                                            item.ratePlans
                                                                .length > 0,
                                                    },
                                                ]}
                                            />
                                        </TableCell>
                                    </TableRow>
                                    {openIds.has(item.mappingId) &&
                                        item.ratePlans.length === 0 && (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={2}
                                                    className="text-muted-foreground"
                                                >
                                                    No rate plans.
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    {openIds.has(item.mappingId) &&
                                        item.ratePlans.map((plan) => (
                                            <TableRow key={plan.id}>
                                                <TableCell className="ps-8">
                                                    {plan.name}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <RemoveMenu
                                                        label={`Remove options for ${plan.name}`}
                                                        items={[
                                                            {
                                                                text: 'Remove rate plan',
                                                                title: `Remove ${plan.name} from Channex?`,
                                                                description: `${plan.name} on ${item.name} is deleted at Channex. Channex refuses while an OTA uses it.`,
                                                                action: destroyRatePlan.form(
                                                                    {
                                                                        property:
                                                                            code,
                                                                        ratePlanMapping:
                                                                            plan.id,
                                                                    },
                                                                ),
                                                            },
                                                        ]}
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                </Fragment>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
        </div>
    );
}

type RemoveItem = {
    /** The menu item text. */
    text: string;
    title: string;
    description: string;
    action: ReturnType<typeof destroyRatePlan.form>;
    disabled?: boolean;
};

/**
 * A row's actions on On Channex: a trash icon menu (every item removes) whose items each open an AlertDialog that asks before removing at Channex.
 */
function RemoveMenu({ label, items }: { label: string; items: RemoveItem[] }) {
    const [asking, setAsking] = useState<RemoveItem | null>(null);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger
                    render={<Button variant="ghost" size="icon-sm" />}
                    aria-label={label}
                >
                    <Trash2 />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-auto min-w-48">
                    {items.map((item) => (
                        <DropdownMenuItem
                            key={item.text}
                            variant="destructive"
                            disabled={item.disabled}
                            onClick={() => setAsking(item)}
                        >
                            <Trash2 />
                            {item.text}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            <AlertDialog
                open={asking !== null}
                onOpenChange={(open) => !open && setAsking(null)}
            >
                <AlertDialogContent>
                    {asking && (
                        <>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    {asking.title}
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    {asking.description}
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <Form
                                {...asking.action}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setAsking(null)}
                            >
                                {({ processing }) => (
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>
                                            Cancel
                                        </AlertDialogCancel>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && (
                                                <Spinner data-icon="inline-start" />
                                            )}
                                            Remove from Channex
                                        </Button>
                                    </AlertDialogFooter>
                                )}
                            </Form>
                        </>
                    )}
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

const bookingNotSaved: App.Data.Shared.BadgeData = {
    value: 'booking_not_saved',
    label: 'Booking not saved',
    tone: 'danger',
};

/** Retry for a booking that was not saved, Close for an alert. Both post and stay on the page. */
function AlertButton({
    action,
    kind,
}: {
    action: ReturnType<typeof retryRevision.form>;
    kind: 'retry' | 'close';
}) {
    return (
        <Form {...action} options={{ preserveScroll: true }}>
            {({ processing }) => (
                <Button
                    type="submit"
                    size="sm"
                    variant="outline"
                    disabled={processing}
                    focusableWhenDisabled
                >
                    {processing ? (
                        <Spinner data-icon="inline-start" />
                    ) : kind === 'retry' ? (
                        <RotateCcw data-icon="inline-start" />
                    ) : (
                        <X data-icon="inline-start" />
                    )}
                    {kind === 'retry' ? 'Retry' : 'Close'}
                </Button>
            )}
        </Form>
    );
}

/**
 * The Alerts section: one table row per booking that was not saved (Retry) and per open Channex alert (Close).
 */
function Alerts({
    code,
    failed,
    alerts,
}: {
    code: string;
    failed: Revision[];
    alerts: ChannelAlert[];
}) {
    const count = failed.length + alerts.length;

    return (
        <div className="flex flex-col gap-4">
            <SectionHeading
                icon={TriangleAlert}
                title="Alerts"
                description={
                    count === 0
                        ? 'Nothing needs attention.'
                        : `${count} open. A booking that is not saved is not acknowledged to Channex.`
                }
            />
            {count > 0 && (
                <div className="overflow-x-auto rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Alert</TableHead>
                                <TableHead>Channel</TableHead>
                                <TableHead>Booking</TableHead>
                                <TableHead>Detail</TableHead>
                                <TableHead>When</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {failed.map((revision) => (
                                <TableRow key={`revision-${revision.id}`}>
                                    <TableCell>
                                        <StatusBadge status={bookingNotSaved} />
                                    </TableCell>
                                    <TableCell>{revision.channel}</TableCell>
                                    <TableCell className="font-medium tabular-nums">
                                        {revision.bookingCode}
                                    </TableCell>
                                    <TableCell className="min-w-56 whitespace-normal text-muted-foreground">
                                        {revision.error ?? '—'}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {shortDate.format(
                                            new Date(revision.receivedAt),
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <AlertButton
                                            kind="retry"
                                            action={retryRevision.form({
                                                property: code,
                                                revision: revision.id,
                                            })}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                            {alerts.map((alert) => (
                                <TableRow key={`alert-${alert.id}`}>
                                    <TableCell>
                                        <StatusBadge status={alert.event} />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        —
                                    </TableCell>
                                    <TableCell className="font-medium tabular-nums">
                                        {alert.bookingId ?? '—'}
                                    </TableCell>
                                    <TableCell className="min-w-56 whitespace-normal text-muted-foreground">
                                        {alert.detail ?? '—'}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {shortDate.format(
                                            new Date(alert.createdAt),
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <AlertButton
                                            kind="close"
                                            action={resolveAlert.form({
                                                property: code,
                                                alert: alert.id,
                                            })}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
        </div>
    );
}

function RecentBookings({ items }: { items: Revision[] }) {
    return (
        <div className="flex flex-col gap-4">
            <SectionHeading
                icon={CalendarRange}
                title="Recent bookings from channels"
                description="The last 20 revisions Channex sent."
            />
            <div className="overflow-x-auto rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Booking</TableHead>
                            <TableHead>Channel</TableHead>
                            <TableHead>Kind</TableHead>
                            <TableHead>Guest</TableHead>
                            <TableHead>Stay</TableHead>
                            <TableHead>Rooms</TableHead>
                            <TableHead>Reservation</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {items.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={7}
                                    className="py-8 text-center text-muted-foreground"
                                >
                                    No bookings from channels yet.
                                </TableCell>
                            </TableRow>
                        )}
                        {items.map((item) => (
                            <TableRow key={item.id}>
                                <TableCell className="font-medium">
                                    {item.bookingCode}
                                </TableCell>
                                <TableCell>{item.channel}</TableCell>
                                <TableCell>
                                    <StatusBadge status={item.kind} />
                                </TableCell>
                                <TableCell>{item.guest ?? '—'}</TableCell>
                                <TableCell>
                                    {item.arrival} – {item.departure}
                                </TableCell>
                                <TableCell>{item.rooms}</TableCell>
                                <TableCell>
                                    {item.reservationReference ??
                                        (item.error ? 'Not saved' : 'Waiting')}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
