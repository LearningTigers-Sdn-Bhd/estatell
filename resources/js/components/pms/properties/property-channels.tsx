import { Form, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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

/**
 * The admin Channels tab body: the Channex card with its sync preview, and the room types and rate plans Channex has.
 */
export function PropertyChannels({
    code,
    channel,
}: {
    code: string;
    channel: Channel;
}) {
    const notSynced = channel.status.value === 'not_synced';
    const syncing = useSyncProgressToast(channel.sync);

    return (
        <div className="flex flex-col gap-6">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        Channex
                        <StatusBadge status={channel.status} />
                    </CardTitle>
                    <CardDescription>
                        {notSynced
                            ? "Send this property's room types and rate plans to Channex."
                            : syncedLine(channel)}
                    </CardDescription>
                </CardHeader>
                {syncing && (
                    <CardContent>
                        <Progress
                            value={
                                channel.sync.total > 0
                                    ? (channel.sync.done / channel.sync.total) *
                                      100
                                    : null
                            }
                        >
                            <span className="text-sm text-muted-foreground">
                                {channel.sync.total > 0
                                    ? `Syncing to Channex: ${channel.sync.done} of ${channel.sync.total} steps. You can leave this page.`
                                    : 'Waiting to start. You can leave this page.'}
                            </span>
                        </Progress>
                    </CardContent>
                )}
                {channel.pausedUntil && (
                    <CardContent>
                        <Alert>
                            <AlertTitle>
                                Paused until{' '}
                                {new Date(
                                    channel.pausedUntil,
                                ).toLocaleTimeString('en-GB')}
                            </AlertTitle>
                            <AlertDescription>
                                Channex asked us to wait.{' '}
                                {channel.waitingChanges} changes waiting, none
                                lost.
                            </AlertDescription>
                        </Alert>
                    </CardContent>
                )}
                {channel.lastError && (
                    <CardContent>
                        <Alert variant="destructive">
                            <AlertTitle>
                                The last sync did not finish
                            </AlertTitle>
                            <AlertDescription>
                                {channel.lastError}
                            </AlertDescription>
                        </Alert>
                    </CardContent>
                )}
                <CardFooter className="justify-end gap-2">
                    {channel.canFullRefresh && <FullRefresh code={code} />}
                    <SyncPreview
                        code={code}
                        preview={channel.preview}
                        label={notSynced ? 'Preview sync' : 'Sync again'}
                        disabled={syncing}
                    />
                </CardFooter>
            </Card>

            {notSynced ? (
                <Card>
                    <CardHeader>
                        <CardTitle>OTAs</CardTitle>
                        <CardDescription>
                            Sync to Channex first.
                        </CardDescription>
                    </CardHeader>
                </Card>
            ) : (
                <>
                    <SyncedItems code={code} items={channel.synced} />
                    <Alerts
                        code={code}
                        failed={channel.failedBookings}
                        alerts={channel.alerts}
                    />
                    <RecentBookings items={channel.recentBookings} />
                </>
            )}
        </div>
    );
}

function syncedLine(channel: Channel): string {
    const { roomTypes, ratePlans, skipped } = channel.preview;
    const when = channel.structureSyncedAt
        ? `Structure synced ${new Date(channel.structureSyncedAt).toLocaleString('en-GB')}`
        : 'Structure not synced yet';

    const sent = channel.changesSentAt
        ? ` · Changes sent ${new Date(channel.changesSentAt).toLocaleString('en-GB')}`
        : '';
    const full = channel.fullSyncedAt
        ? ` · Full refresh ${new Date(channel.fullSyncedAt).toLocaleString('en-GB')}`
        : '';

    return `${when} · ${roomTypes} room types · ${ratePlans} rate plans · ${skipped} skipped${sent}${full}`;
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
                    {processing && <Spinner data-icon="inline-start" />}
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
    return (
        <Card>
            <CardHeader>
                <CardTitle>On Channex</CardTitle>
                <CardDescription>
                    Staff cannot archive these until you remove them here.
                    Channex refuses a removal while an OTA still uses the item.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {items.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Nothing is on Channex.
                    </p>
                )}
                {items.map((item) => (
                    <div key={item.mappingId} className="rounded-lg border">
                        <div className="flex items-center justify-between gap-2 border-b p-3">
                            <span className="font-medium">{item.name}</span>
                            <RemoveButton
                                label={`Remove ${item.name}`}
                                title={`Remove ${item.name} from Channex?`}
                                description="Remove its rate plans first. Channex refuses while an OTA uses it."
                                action={destroyRoomType.form({
                                    property: code,
                                    roomTypeMapping: item.mappingId,
                                })}
                                disabled={item.ratePlans.length > 0}
                            />
                        </div>
                        <ul className="flex flex-col">
                            {item.ratePlans.length === 0 && (
                                <li className="p-3 text-sm text-muted-foreground">
                                    No rate plans.
                                </li>
                            )}
                            {item.ratePlans.map((plan) => (
                                <li
                                    key={plan.id}
                                    className="flex items-center justify-between gap-2 p-3 text-sm not-last:border-b"
                                >
                                    {plan.name}
                                    <RemoveButton
                                        label={`Remove ${plan.name}`}
                                        title={`Remove ${plan.name} from Channex?`}
                                        description={`${plan.name} on ${item.name} is deleted at Channex. Channex refuses while an OTA uses it.`}
                                        action={destroyRatePlan.form({
                                            property: code,
                                            ratePlanMapping: plan.id,
                                        })}
                                    />
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

function RemoveButton({
    label,
    title,
    description,
    action,
    disabled = false,
}: {
    label: string;
    title: string;
    description: string;
    action: ReturnType<typeof destroyRatePlan.form>;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger
                render={
                    <Button
                        size="sm"
                        variant="outline"
                        aria-label={label}
                        disabled={disabled}
                    />
                }
            >
                Remove
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...action}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
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
            </AlertDialogContent>
        </AlertDialog>
    );
}

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
        <Card>
            <CardHeader>
                <CardTitle>Alerts</CardTitle>
                <CardDescription>
                    {count === 0
                        ? 'Nothing needs attention.'
                        : `${count} open. A booking that is not saved is not acknowledged to Channex.`}
                </CardDescription>
            </CardHeader>
            {count > 0 && (
                <CardContent className="flex flex-col gap-3">
                    {failed.map((revision) => (
                        <div
                            key={`revision-${revision.id}`}
                            className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-3"
                        >
                            <div className="flex flex-col gap-1 text-sm">
                                <span className="font-medium">
                                    {revision.channel} · Booking not saved ·{' '}
                                    {revision.bookingCode}
                                </span>
                                <span className="text-muted-foreground">
                                    {revision.error}
                                </span>
                            </div>
                            <Form
                                {...retryRevision.form({
                                    property: code,
                                    revision: revision.id,
                                })}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Retry
                                    </Button>
                                )}
                            </Form>
                        </div>
                    ))}
                    {alerts.map((alert) => (
                        <div
                            key={`alert-${alert.id}`}
                            className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-3"
                        >
                            <div className="flex flex-col items-start gap-1 text-sm">
                                <StatusBadge status={alert.event} />
                                <span className="text-muted-foreground">
                                    {[alert.detail, alert.bookingId]
                                        .filter(Boolean)
                                        .join(' · ')}{' '}
                                    {new Date(alert.createdAt).toLocaleString(
                                        'en-GB',
                                    )}
                                </span>
                            </div>
                            <Form
                                {...resolveAlert.form({
                                    property: code,
                                    alert: alert.id,
                                })}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Close
                                    </Button>
                                )}
                            </Form>
                        </div>
                    ))}
                </CardContent>
            )}
        </Card>
    );
}

function RecentBookings({ items }: { items: Revision[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Recent bookings from channels</CardTitle>
                <CardDescription>
                    The last 20 revisions Channex sent.
                </CardDescription>
            </CardHeader>
            <CardContent>
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
            </CardContent>
        </Card>
    );
}
