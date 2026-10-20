import { Form } from '@inertiajs/react';
import { useState } from 'react';
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
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { sync } from '@/routes/admin/properties/channels';
import { destroy as destroyRatePlan } from '@/routes/admin/properties/channels/rate-plans';
import { destroy as destroyRoomType } from '@/routes/admin/properties/channels/room-types';

type Channel = App.Data.Channels.ChannelTabData;
type SyncedRoomType = App.Data.Channels.ChannelSyncedRoomTypeData;

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
                <CardFooter className="justify-end">
                    <SyncPreview
                        code={code}
                        preview={channel.preview}
                        label={notSynced ? 'Preview sync' : 'Sync again'}
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
                <SyncedItems code={code} items={channel.synced} />
            )}
        </div>
    );
}

function syncedLine(channel: Channel): string {
    const { roomTypes, ratePlans, skipped } = channel.preview;
    const when = channel.structureSyncedAt
        ? `Structure synced ${new Date(channel.structureSyncedAt).toLocaleString('en-GB')}`
        : 'Structure not synced yet';

    return `${when} · ${roomTypes} room types · ${ratePlans} rate plans · ${skipped} skipped`;
}

function SyncPreview({
    code,
    preview,
    label,
}: {
    code: string;
    preview: App.Data.Channels.ChannelStructurePreviewData;
    label: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button />}>{label}</DialogTrigger>
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
