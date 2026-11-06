import { Head, router, useForm } from '@inertiajs/react';
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
import { PageHeader } from '@/components/pms/page-header';
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
import { Card, CardContent } from '@/components/ui/card';
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
import { revoke } from '@/routes/settings/keyport/devices';
import { cancel } from '@/routes/settings/keyport/jobs';
import { store as storeLock } from '@/routes/settings/keyport/locks';

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

/** One front-desk PC: whether it is online, whether its reader is ready, and the way to stop it. */
function DeviceCard({
    device,
    onRevoke,
}: {
    device: Device;
    onRevoke: (device: Device) => void;
}) {
    return (
        <Card className={cn(device.status.value === 'revoked' && 'opacity-60')}>
            <CardContent className="flex flex-col gap-4">
                <div className="flex items-start gap-3">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                        <Monitor className="size-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">{device.name}</p>
                        <p className="truncate text-sm text-muted-foreground">
                            {device.platform ?? 'Unknown system'}
                        </p>
                    </div>
                    {device.canRevoke && (
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
                                    onClick={() => onRevoke(device)}
                                >
                                    Revoke computer
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
                <div className="flex items-center justify-between gap-3 border-t pt-3 text-sm">
                    <StatusBadge status={device.status} />
                    <div className="text-right text-muted-foreground">
                        <p>Last seen {ago(device.lastSeenAt)}</p>
                        {device.readerNote && (
                            <p className="font-medium text-foreground">
                                {device.readerNote}
                            </p>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
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

export default function KeyportIndex({
    devices,
    locks,
    rooms,
    jobs,
}: {
    devices: Device[];
    locks: Lock[];
    rooms: { value: string; label: string }[];
    jobs: Job[];
}) {
    const [addingLock, setAddingLock] = useState(false);
    const [revoking, setRevoking] = useState<Device | null>(null);
    const [pairing, setPairing] = useState<Pairing | null>(null);
    const [makingCode, setMakingCode] = useState(false);
    const lockForm = useForm({ name: '', lock_no: '', room_id: '' });
    usePollWhile(
        jobs.some((job) => job.open) ||
            devices.some((d) => d.status.value !== 'revoked'),
        { only: ['devices', 'jobs'], every: 5000, giveUpAfter: 600000 },
    );

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
            <Head title="Key cards" />

            <div className="flex flex-1 flex-col gap-8 p-4">
                <PageHeader
                    title="Key cards"
                    description="eLock rooms open with a key card. Estatell Keyport on a front-desk computer writes the card when you check a guest in."
                    actions={addComputer}
                />

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
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            {devices.map((device) => (
                                <DeviceCard
                                    key={device.id}
                                    device={device}
                                    onRevoke={setRevoking}
                                />
                            ))}
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-3">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="text-base font-medium">eLock locks</h2>
                        <Button
                            variant="outline"
                            onClick={() => setAddingLock(true)}
                        >
                            <Plus data-icon="inline-start" />
                            Add lock
                        </Button>
                    </div>
                    {locks.length === 0 ? (
                        <EmptyState
                            icon={KeyRound}
                            title="No eLock locks yet"
                            description="Add one lock for each room that uses key cards. The lock number is the one the card encoder uses, like 1.2.203."
                        />
                    ) : (
                        <Card>
                            <CardContent className="px-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Lock</TableHead>
                                            <TableHead>Lock number</TableHead>
                                            <TableHead>Room</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {locks.map((lock) => (
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
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    )}
                </section>

                <section className="flex flex-col gap-3">
                    <h2 className="text-base font-medium">Recent key cards</h2>
                    {jobs.length === 0 ? (
                        <EmptyState
                            icon={CreditCard}
                            title="No key cards yet"
                            description="A card job appears here when a guest checks in to an eLock room, or when you press Make key card on a stay."
                        />
                    ) : (
                        <Card>
                            <CardContent className="px-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>When</TableHead>
                                            <TableHead>Card</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead />
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
                            </CardContent>
                        </Card>
                    )}
                </section>
            </div>

            <FormOverlay
                variant="dialog"
                open={addingLock}
                onOpenChange={(open) => {
                    setAddingLock(open);

                    if (!open) {
                        lockForm.reset();
                        lockForm.clearErrors();
                    }
                }}
                title="Add eLock lock"
                description="One lock for one room. The lock number must match what the card encoder writes."
                errors={lockForm.errors}
                processing={lockForm.processing}
                submitLabel="Add lock"
                onSubmit={() =>
                    lockForm.post(storeLock.url(), {
                        preserveScroll: true,
                        onSuccess: () => {
                            setAddingLock(false);
                            lockForm.reset();
                        },
                    })
                }
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
        </>
    );
}
