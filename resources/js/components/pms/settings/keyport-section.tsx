import { router, useForm } from '@inertiajs/react';
import {
    Check,
    Copy,
    CreditCard,
    KeyRound,
    Monitor,
    MoreHorizontal,
    Plus,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    PaginationFooter,
    toPaginationData,
} from '@/components/pms/pagination-footer';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useClipboard } from '@/hooks/use-clipboard';
import { usePollWhile } from '@/hooks/use-poll-while';
import { cn } from '@/lib/utils';
import { store as storeCode } from '@/routes/settings/keyport/codes';
import { destroy, revoke } from '@/routes/settings/keyport/devices';
import { cancel } from '@/routes/settings/keyport/jobs';
import {
    destroy as destroyLock,
    store as storeLock,
    update as updateLock,
} from '@/routes/settings/keyport/locks';

type Device = App.Data.Keyport.KeyportDeviceData;
type Job = App.Data.Keyport.KeyportJobRowData;
type Lock = App.Data.DoorLocks.DoorLockData;
type Pairing = { code: string; expiresAt: string | null };

const TIME: Intl.DateTimeFormatOptions = {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
};

const when = (iso: string | null) =>
    iso === null ? '–' : new Date(iso).toLocaleString('en-GB', TIME);

/** How long ago, for the "last seen" line. */
function ago(iso: string | null): string {
    if (iso === null) {
        return 'Never';
    }

    const minutes = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );

    if (minutes < 1) {
        return 'Just now';
    }

    if (minutes < 60) {
        return `${minutes} min ago`;
    }

    return minutes < 60 * 24 ? `${Math.round(minutes / 60)} h ago` : when(iso);
}

/** One front-desk PC as a table row: whether it is online, whether its reader is ready, and the way to stop or remove it. */
function DeviceRow({
    device,
    onRevoke,
    onRemove,
}: {
    device: Device;
    onRevoke: (device: Device) => void;
    onRemove: (device: Device) => void;
}) {
    const revoked = device.status.value === 'revoked';

    return (
        <TableRow className={cn(revoked && 'opacity-60')}>
            <TableCell>
                <div className="flex items-center gap-3">
                    <Monitor className="size-4 shrink-0 text-muted-foreground" />
                    <div className="min-w-0">
                        <p className="truncate font-medium">{device.name}</p>
                        <p className="truncate text-sm text-muted-foreground">
                            {device.platform ?? 'Unknown system'}
                        </p>
                    </div>
                </div>
            </TableCell>
            <TableCell>
                <StatusBadge status={device.status} />
            </TableCell>
            <TableCell className="text-muted-foreground">
                <p>Last seen {ago(device.lastSeenAt)}</p>
                {device.readerNote && (
                    <p className="text-foreground">{device.readerNote}</p>
                )}
            </TableCell>
            <TableCell className="text-right">
                {(device.canRevoke || revoked) && (
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            render={
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={`Actions for ${device.name}`}
                                />
                            }
                        >
                            <MoreHorizontal />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                variant="destructive"
                                onClick={() =>
                                    revoked
                                        ? onRemove(device)
                                        : onRevoke(device)
                                }
                            >
                                {revoked
                                    ? 'Remove from list'
                                    : 'Revoke computer'}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </TableCell>
        </TableRow>
    );
}

/** The pairing code in large type, with a copy button. */
function PairingCode({ code }: { code: string }) {
    const [copied, copy] = useClipboard();
    const plain = code.replace('-', '');

    return (
        <div className="flex flex-col items-center gap-3 rounded-lg bg-muted py-6">
            <p
                className="font-mono text-4xl font-semibold tracking-[0.3em] tabular-nums"
                aria-label={`Pairing code ${plain.split('').join(' ')}`}
            >
                {code}
            </p>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => copy(plain)}
            >
                {copied === plain ? (
                    <Check data-icon="inline-start" />
                ) : (
                    <Copy data-icon="inline-start" />
                )}
                {copied === plain ? 'Copied' : 'Copy code'}
            </Button>
        </div>
    );
}

/** eLock locks, the front-desk computers that write their cards, and the latest card jobs. */
export function KeyportSection({
    devices,
    locks: lockPage,
    lockTotal,
    lockSearch,
    rooms,
    jobs: jobPage,
}: {
    devices: Device[];
    locks: Illuminate.LengthAwarePaginator<number, Lock>;
    lockTotal: number;
    lockSearch: string;
    rooms: { value: string; label: string }[];
    jobs: Illuminate.LengthAwarePaginator<number, Job>;
}) {
    const locks = lockPage.data;
    const jobs = jobPage.data;
    const [addingLock, setAddingLock] = useState(false);
    const [editingLock, setEditingLock] = useState<Lock | null>(null);
    const [removingLock, setRemovingLock] = useState<Lock | null>(null);
    const [revoking, setRevoking] = useState<Device | null>(null);
    const [pairing, setPairing] = useState<Pairing | null>(null);
    const [makingCode, setMakingCode] = useState(false);
    const [removing, setRemoving] = useState<Device | null>(null);
    const [showRevoked, setShowRevoked] = useState(false);
    const [lockQuery, setLockQuery] = useState(lockSearch);
    const liveDevices = devices.filter((d) => d.status.value !== 'revoked');
    const revokedDevices = devices.filter((d) => d.status.value === 'revoked');
    const shownDevices = showRevoked ? devices : liveDevices;
    const lockForm = useForm({ name: '', lock_no: '', room_id: '' });
    const formOpen = addingLock || editingLock !== null;

    function openEdit(lock: Lock) {
        lockForm.clearErrors();
        lockForm.setData({
            name: lock.name,
            lock_no: lock.lockNo ?? '',
            room_id: lock.roomId === null ? '' : String(lock.roomId),
        });
        setEditingLock(lock);
    }

    function closeForm() {
        setAddingLock(false);
        setEditingLock(null);
        lockForm.reset();
        lockForm.clearErrors();
    }
    usePollWhile(
        jobs.some((job) => job.open) ||
            devices.some((d) => d.status.value !== 'revoked'),
        { only: ['keyport'], every: 5000, giveUpAfter: 600000 },
    );

    // Search runs on the server so it covers every page. A short pause keeps it from firing on each key.
    useEffect(() => {
        if (lockQuery.trim() === lockSearch) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                window.location.pathname,
                { lock_search: lockQuery.trim() || undefined },
                {
                    only: ['keyport'],
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [lockQuery, lockSearch]);

    // The pairing code arrives once, as flash data on the redirect after "Add computer".
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;

            if (flash?.pairing) {
                setPairing(flash.pairing as Pairing);
            }
        });
    }, []);

    const addComputer = (
        <Button
            disabled={makingCode}
            onClick={() =>
                router.post(
                    storeCode.url(),
                    {},
                    {
                        preserveScroll: true,
                        onStart: () => setMakingCode(true),
                        onFinish: () => setMakingCode(false),
                    },
                )
            }
        >
            <Plus data-icon="inline-start" />
            Add computer
        </Button>
    );

    return (
        <>
            <div className="flex flex-col gap-8">
                <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                    <p className="max-w-prose text-sm text-muted-foreground">
                        eLock rooms open with a key card. Estatell Keyport on a
                        front-desk computer writes the card when you check a
                        guest in.
                    </p>
                    {addComputer}
                </div>

                <section className="flex flex-col gap-3">
                    <h2 className="text-base font-medium">
                        Front-desk computers
                    </h2>
                    {devices.length === 0 ? (
                        <EmptyState
                            icon={Monitor}
                            title="No computer yet"
                            description="Install Estatell Keyport on the front-desk PC that has the card encoder. Then click Add computer and type the code into Keyport."
                            action={addComputer}
                        />
                    ) : (
                        <>
                            <div className="overflow-hidden rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Computer</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Reader</TableHead>
                                            <TableHead className="w-10">
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {shownDevices.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={4}
                                                    className="text-muted-foreground"
                                                >
                                                    No active computer.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            shownDevices.map((device) => (
                                                <DeviceRow
                                                    key={device.id}
                                                    device={device}
                                                    onRevoke={setRevoking}
                                                    onRemove={setRemoving}
                                                />
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                            {revokedDevices.length > 0 && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="self-start"
                                    onClick={() => setShowRevoked(!showRevoked)}
                                >
                                    {showRevoked
                                        ? 'Hide revoked'
                                        : `Show revoked (${revokedDevices.length})`}
                                </Button>
                            )}
                        </>
                    )}
                </section>

                <section className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-base font-medium">eLock locks</h2>
                        <div className="flex items-center gap-2">
                            {lockTotal > 10 && (
                                <Input
                                    type="search"
                                    aria-label="Search locks"
                                    placeholder="Search lock, number or room"
                                    value={lockQuery}
                                    onChange={(event) =>
                                        setLockQuery(event.target.value)
                                    }
                                    className="w-64"
                                />
                            )}
                            <Button
                                variant="outline"
                                onClick={() => setAddingLock(true)}
                            >
                                <Plus data-icon="inline-start" />
                                Add lock
                            </Button>
                        </div>
                    </div>
                    {lockTotal === 0 ? (
                        <EmptyState
                            icon={KeyRound}
                            title="No eLock locks yet"
                            description="Add one lock for each room that uses key cards. The lock number is the one the card encoder uses, like 1.2.203."
                        />
                    ) : (
                        <>
                            <div className="overflow-hidden rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Lock</TableHead>
                                            <TableHead>Lock number</TableHead>
                                            <TableHead>Room</TableHead>
                                            <TableHead className="w-10">
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {locks.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={4}
                                                    className="text-muted-foreground"
                                                >
                                                    No lock matches "
                                                    {lockSearch}".
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            locks.map((lock) => (
                                                <TableRow key={lock.id}>
                                                    <TableCell className="font-medium">
                                                        {lock.name}
                                                    </TableCell>
                                                    <TableCell className="font-mono tabular-nums">
                                                        {lock.lockNo}
                                                    </TableCell>
                                                    <TableCell>
                                                        {lock.roomNumber ?? (
                                                            <span className="text-muted-foreground">
                                                                Not linked
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger
                                                                render={
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon-sm"
                                                                        aria-label={`Actions for ${lock.name}`}
                                                                    />
                                                                }
                                                            >
                                                                <MoreHorizontal />
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuItem
                                                                    onClick={() =>
                                                                        openEdit(
                                                                            lock,
                                                                        )
                                                                    }
                                                                >
                                                                    Edit lock
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem
                                                                    variant="destructive"
                                                                    onClick={() =>
                                                                        setRemovingLock(
                                                                            lock,
                                                                        )
                                                                    }
                                                                >
                                                                    Remove lock
                                                                </DropdownMenuItem>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                            <PaginationFooter
                                pagination={toPaginationData(
                                    lockPage.meta,
                                    'locks_page',
                                )}
                            />
                        </>
                    )}
                </section>

                <section className="flex flex-col gap-3">
                    <h2 className="text-base font-medium">Recent key cards</h2>
                    {jobPage.meta.total === 0 ? (
                        <EmptyState
                            icon={CreditCard}
                            title="No key cards yet"
                            description="A card job appears here when a guest checks in to an eLock room, or when you press Make key card on a stay."
                        />
                    ) : (
                        <>
                            <div className="overflow-hidden rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>When</TableHead>
                                            <TableHead>Card</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="w-10">
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {jobs.map((job) => (
                                            <TableRow key={job.id}>
                                                <TableCell className="whitespace-nowrap text-muted-foreground">
                                                    {when(job.createdAt)}
                                                </TableCell>
                                                <TableCell>
                                                    <p className="font-medium">
                                                        {job.typeLabel}
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        {[
                                                            job.lockName,
                                                            job.guest,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </p>
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={job.status}
                                                    />
                                                    {job.message && (
                                                        <p className="mt-1 max-w-xs text-sm text-muted-foreground">
                                                            {job.message}
                                                        </p>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {job.canCancel && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                router.post(
                                                                    cancel.url({
                                                                        keyportJob:
                                                                            job.id,
                                                                    }),
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Cancel
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            <PaginationFooter
                                pagination={toPaginationData(
                                    jobPage.meta,
                                    'jobs_page',
                                )}
                            />
                        </>
                    )}
                </section>
            </div>

            <FormOverlay
                variant="dialog"
                open={formOpen}
                onOpenChange={(open) => !open && closeForm()}
                title={
                    editingLock ? `Edit ${editingLock.name}` : 'Add eLock lock'
                }
                description={
                    editingLock
                        ? 'Cards already written keep the old lock number. Change it only to fix a typo.'
                        : 'One lock for one room. The lock number must match what the card encoder writes.'
                }
                errors={lockForm.errors}
                processing={lockForm.processing}
                submitLabel={editingLock ? 'Save lock' : 'Add lock'}
                onSubmit={() => {
                    const options = {
                        preserveScroll: true,
                        onSuccess: closeForm,
                    };

                    if (editingLock) {
                        lockForm.put(
                            updateLock.url({ doorLock: editingLock.id }),
                            options,
                        );
                    } else {
                        lockForm.post(storeLock.url(), options);
                    }
                }}
            >
                <Field data-invalid={!!lockForm.errors.name}>
                    <FieldLabel htmlFor="lock-name">Name</FieldLabel>
                    <Input
                        id="lock-name"
                        placeholder="e.g. Room 203 door"
                        maxLength={100}
                        value={lockForm.data.name}
                        onChange={(event) =>
                            lockForm.setData('name', event.target.value)
                        }
                        aria-invalid={!!lockForm.errors.name}
                        autoFocus
                    />
                    <FieldError>{lockForm.errors.name}</FieldError>
                </Field>
                <Field data-invalid={!!lockForm.errors.lock_no}>
                    <FieldLabel htmlFor="lock-no">Lock number</FieldLabel>
                    <Input
                        id="lock-no"
                        placeholder="1.2.203"
                        inputMode="decimal"
                        maxLength={40}
                        className="font-mono"
                        value={lockForm.data.lock_no}
                        onChange={(event) =>
                            lockForm.setData('lock_no', event.target.value)
                        }
                        aria-invalid={!!lockForm.errors.lock_no}
                    />
                    <FieldDescription>
                        Building, floor, room. Example: 1.2.203.
                    </FieldDescription>
                    <FieldError>{lockForm.errors.lock_no}</FieldError>
                </Field>
                <Field data-invalid={!!lockForm.errors.room_id}>
                    <FieldLabel>Room</FieldLabel>
                    <EnumSelect
                        aria-label="Room"
                        options={rooms}
                        value={lockForm.data.room_id}
                        allLabel="Not linked yet"
                        onValueChange={(value) =>
                            lockForm.setData('room_id', value)
                        }
                    />
                    <FieldError>{lockForm.errors.room_id}</FieldError>
                </Field>
            </FormOverlay>

            <Dialog
                open={pairing !== null}
                onOpenChange={(open) => !open && setPairing(null)}
            >
                <DialogContent>
                    {pairing && (
                        <>
                            <DialogHeader>
                                <DialogTitle>Pairing code</DialogTitle>
                                <DialogDescription>
                                    Type this code into Keyport on the
                                    front-desk computer. It works once and
                                    expires at {when(pairing.expiresAt)}. You
                                    will not see it again.
                                </DialogDescription>
                            </DialogHeader>
                            <PairingCode code={pairing.code} />
                            <DialogFooter>
                                <Button onClick={() => setPairing(null)}>
                                    Done
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
            >
                <AlertDialogContent>
                    {revoking && (
                        <>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Revoke {revoking.name}?
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    Keyport on this computer stops working at
                                    once and shows "unpaired". To use it again,
                                    add it as a new computer.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Keep it</AlertDialogCancel>
                                <AlertDialogAction
                                    variant="destructive"
                                    onClick={() =>
                                        router.post(
                                            revoke.url({
                                                keyportDevice: revoking.id,
                                            }),
                                            {},
                                            {
                                                preserveScroll: true,
                                                onFinish: () =>
                                                    setRevoking(null),
                                            },
                                        )
                                    }
                                >
                                    Revoke
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </>
                    )}
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
            >
                <AlertDialogContent>
                    {removing && (
                        <>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Remove {removing.name} from the list?
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    It is already revoked, so it cannot connect.
                                    This removes it from the list. Its past key
                                    cards stay in the history.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Keep it</AlertDialogCancel>
                                <AlertDialogAction
                                    variant="destructive"
                                    onClick={() =>
                                        router.delete(
                                            destroy.url({
                                                keyportDevice: removing.id,
                                            }),
                                            {
                                                preserveScroll: true,
                                                onFinish: () =>
                                                    setRemoving(null),
                                            },
                                        )
                                    }
                                >
                                    Remove
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </>
                    )}
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog
                open={removingLock !== null}
                onOpenChange={(open) => !open && setRemovingLock(null)}
            >
                <AlertDialogContent>
                    {removingLock && (
                        <>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Remove {removingLock.name}?
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    The room can then take another lock. Past
                                    key cards stay in the history. Cards already
                                    written at this door are not erased.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Keep it</AlertDialogCancel>
                                <AlertDialogAction
                                    variant="destructive"
                                    onClick={() =>
                                        router.delete(
                                            destroyLock.url({
                                                doorLock: removingLock.id,
                                            }),
                                            {
                                                preserveScroll: true,
                                                onFinish: () =>
                                                    setRemovingLock(null),
                                            },
                                        )
                                    }
                                >
                                    Remove
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </>
                    )}
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
