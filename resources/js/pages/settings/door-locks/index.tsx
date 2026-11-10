import { Head, router, useForm } from '@inertiajs/react';
import {
    Activity,
    CircleCheck,
    Clock,
    KeyRound,
    Lock,
    MoreHorizontal,
    Plug,
    RefreshCw,
    Unlock,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    PaginationFooter,
    toPaginationData,
} from '@/components/pms/pagination-footer';
import { KeyportSection } from '@/components/pms/settings/keyport-section';
import DoorLockSettingsController from '@/actions/App/Http/Controllers/DoorLocks/DoorLockSettingsController';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PageHeader } from '@/components/pms/page-header';
import {
    SettingsInput,
    SettingsSelect,
    SettingsSwitch,
} from '@/components/pms/settings/form-fields';
import { SettingsSection } from '@/components/pms/settings-section';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { profile } from '@/routes/settings';
import {
    edit,
    sync,
    test as testConnection,
} from '@/routes/settings/door-locks';
import { cn } from '@/lib/utils';

const GATEWAY_NOTE =
    'This needs a gateway installed at the lock. Without one, only generated PINs work.';

/** Date and time in the viewer's locale. */
function when(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function clockText(seconds: number): { text: string; warn: boolean } {
    const minutes = Math.round(Math.abs(seconds) / 60);

    if (Math.abs(seconds) <= 60) {
        return { text: 'Correct', warn: false };
    }

    return {
        text: `${minutes} min ${seconds > 0 ? 'fast' : 'slow'}`,
        warn: Math.abs(seconds) > 300,
    };
}

/** Battery, gateway, clock and last check of a lock as table cells, as last read from TTLock. */
function LockHealthCells({ lock }: { lock: App.Data.DoorLocks.DoorLockData }) {
    const clock =
        lock.clockDriftSeconds === null
            ? null
            : clockText(lock.clockDriftSeconds);
    const lowBattery = lock.batteryPercent !== null && lock.batteryPercent < 20;

    return (
        <>
            <TableCell
                className={cn(
                    'tabular-nums',
                    lowBattery && 'font-medium text-warning',
                )}
            >
                {lock.batteryPercent === null ? '–' : `${lock.batteryPercent}%`}
            </TableCell>
            <TableCell>
                {!lock.hasGateway ? (
                    <span
                        className="text-muted-foreground"
                        title={GATEWAY_NOTE}
                    >
                        No gateway
                    </span>
                ) : lock.gatewayOnline === null ? (
                    <span className="text-muted-foreground">Not checked</span>
                ) : (
                    <span
                        className={cn(
                            !lock.gatewayOnline && 'font-medium text-warning',
                        )}
                    >
                        {lock.gatewayName ?? 'Gateway'} ·{' '}
                        {lock.gatewayOnline ? 'online' : 'offline'}
                    </span>
                )}
            </TableCell>
            <TableCell
                className={cn(clock?.warn && 'font-medium text-warning')}
            >
                {!lock.hasGateway
                    ? '–'
                    : (clock?.text ?? (
                          <span className="text-muted-foreground">
                              Not checked
                          </span>
                      ))}
            </TableCell>
            <TableCell className="hidden text-muted-foreground tabular-nums lg:table-cell">
                {lock.healthCheckedAt === null
                    ? 'Never'
                    : when(lock.healthCheckedAt)}
            </TableCell>
        </>
    );
}

const PIN_LENGTHS = [4, 5, 6, 7, 8, 9].map((length) => ({
    value: String(length),
    label: length === 6 ? '6 digits (recommended)' : `${length} digits`,
}));

export default function DoorLocksIndex({
    connection,
    passcodeModes,
    passcodeKinds,
    locks,
    rooms,
    lockTotal,
    lockSearch,
    lockOptions,
    passcodes,
    keyport,
}: {
    connection: App.Data.DoorLocks.DoorLockConnectionData | null;
    passcodeModes: { value: string; label: string; description: string }[];
    passcodeKinds: { value: string; label: string }[];
    lockTotal: number;
    lockSearch: string;
    lockOptions: { value: string; label: string; hasGateway: boolean }[];
    locks: Illuminate.LengthAwarePaginator<
        number,
        App.Data.DoorLocks.DoorLockData
    >;
    rooms: { value: string; label: string }[];
    passcodes: Illuminate.LengthAwarePaginator<
        number,
        App.Data.DoorLocks.DoorPasscodeData
    >;
    keyport: {
        devices: App.Data.Keyport.KeyportDeviceData[];
        lockTotal: number;
        lockSearch: string;
        locks: Illuminate.LengthAwarePaginator<
            number,
            App.Data.DoorLocks.DoorLockData
        >;
        jobs: Illuminate.LengthAwarePaginator<
            number,
            App.Data.Keyport.KeyportJobRowData
        >;
    };
}) {
    // A property with only eLock locks opens on that tab. Both kinds can live side by side.
    const [view, setView] = useState<'ttlock' | 'elock'>(
        lockTotal === 0 && keyport.lockTotal > 0 ? 'elock' : 'ttlock',
    );
    const [sheetOpen, setSheetOpen] = useState(false);
    const [lockQuery, setLockQuery] = useState(lockSearch);

    // Search runs on the server so it covers every page. A short pause keeps it from firing on each key.
    useEffect(() => {
        if (lockQuery.trim() === lockSearch) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                window.location.pathname,
                { ttlock_search: lockQuery.trim() || undefined },
                {
                    only: ['locks', 'lockSearch'],
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [lockQuery, lockSearch]);
    const [mode, setMode] = useState(connection?.passcodeMode ?? 'generated');
    const lockGenerated = mode === 'generated';
    const action = useForm({});
    const pin = useForm({
        door_lock_id: lockOptions[0]?.value ?? '',
        kind: passcodeKinds[0]?.value ?? 'generated',
        label: '',
        code: '',
        starts_at: '',
        ends_at: '',
    });
    const pinErrors = pin.errors as Record<string, string | undefined>;

    const selectedLock = lockOptions.find(
        (lock) => lock.value === pin.data.door_lock_id,
    );
    const needsGateway =
        pin.data.kind === 'custom' && selectedLock?.hasGateway === false;
    const anyWithoutGateway = lockOptions.some((lock) => !lock.hasGateway);

    const post = (url: string) => action.post(url, { preserveScroll: true });

    const issuePin = () =>
        pin.post(DoorLockSettingsController.storePasscode.url(), {
            preserveScroll: true,
            onSuccess: () => {
                setSheetOpen(false);
                pin.reset('label', 'code', 'starts_at', 'ends_at');
            },
        });

    return (
        <>
            <Head title="Door locks" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Door locks"
                    description="Give each checked-in stay a PIN or key card for its room door, and take it away at check-out."
                />

                <Tabs
                    value={view}
                    onValueChange={(value) =>
                        setView(value === 'elock' ? 'elock' : 'ttlock')
                    }
                >
                    <TabsList>
                        <TabsTrigger value="ttlock">
                            TTLock ({lockTotal})
                        </TabsTrigger>
                        <TabsTrigger value="elock">
                            eLock ({keyport.lockTotal})
                        </TabsTrigger>
                    </TabsList>
                </Tabs>

                {view === 'elock' ? (
                    <KeyportSection
                        devices={keyport.devices}
                        locks={keyport.locks}
                        lockTotal={keyport.lockTotal}
                        lockSearch={keyport.lockSearch}
                        rooms={rooms}
                        jobs={keyport.jobs}
                    />
                ) : (
                    <>
                        {connection?.signedIn && anyWithoutGateway && (
                            <Alert>
                                <AlertTitle>
                                    Some locks have no gateway
                                </AlertTitle>
                                <AlertDescription>
                                    {GATEWAY_NOTE} Custom PINs, remote unlock
                                    and removing a PIN before it ends need a
                                    TTLock gateway installed within range of the
                                    lock.
                                </AlertDescription>
                            </Alert>
                        )}

                        <Card>
                            <CardContent>
                                <SettingsSection
                                    guardUnsavedChanges
                                    form={DoorLockSettingsController.update.form()}
                                    title="TTLock account"
                                    description="Sign in with the TTLock app account that owns the locks. The password is used once and is not saved."
                                    submitLabel="Save connection"
                                    contentClassName="grid gap-5 md:grid-cols-2"
                                    actions={
                                        <>
                                            {connection?.signedIn ? (
                                                <Badge variant="secondary">
                                                    <CircleCheck />
                                                    Signed in
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline">
                                                    Not signed in
                                                </Badge>
                                            )}
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={
                                                    connection === null ||
                                                    action.processing
                                                }
                                                focusableWhenDisabled
                                                onClick={() =>
                                                    post(testConnection.url())
                                                }
                                            >
                                                {action.processing ? (
                                                    <Spinner />
                                                ) : (
                                                    <Plug />
                                                )}
                                                Test connection
                                            </Button>
                                        </>
                                    }
                                >
                                    {(errors) => (
                                        <>
                                            <SettingsInput
                                                name="ttlock_username"
                                                label="TTLock username"
                                                value={
                                                    connection?.ttlockUsername ??
                                                    ''
                                                }
                                                error={errors.ttlock_username}
                                            />
                                            <SettingsInput
                                                name="ttlock_password"
                                                label="TTLock password"
                                                type="password"
                                                value=""
                                                placeholder={
                                                    connection?.signedIn
                                                        ? 'Signed in. Leave blank to keep it.'
                                                        : 'Needed to sign in'
                                                }
                                                error={errors.ttlock_password}
                                            />
                                            <SettingsSelect
                                                name="passcode_mode"
                                                label="PIN for new stays"
                                                value={
                                                    connection?.passcodeMode ??
                                                    'generated'
                                                }
                                                options={passcodeModes}
                                                onValueChange={setMode}
                                                description={
                                                    passcodeModes.find(
                                                        (option) =>
                                                            option.value ===
                                                            mode,
                                                    )?.description
                                                }
                                                error={errors.passcode_mode}
                                            />
                                            <SettingsSelect
                                                name="passcode_length"
                                                label="PIN length"
                                                value={String(
                                                    connection?.passcodeLength ??
                                                        6,
                                                )}
                                                options={PIN_LENGTHS}
                                                disabled={lockGenerated}
                                                description={
                                                    lockGenerated
                                                        ? 'Not used in Lock-generated mode. The lock decides the length.'
                                                        : 'Under 6 digits is easier to guess.'
                                                }
                                                error={errors.passcode_length}
                                            />
                                            <SettingsSwitch
                                                bordered
                                                name="enabled"
                                                label="Issue PINs automatically"
                                                checked={
                                                    connection?.enabled ?? false
                                                }
                                                description="A PIN is issued at check-in and removed at check-out, undo check-in and room move."
                                                error={errors.enabled}
                                            />
                                            {connection?.lastError && (
                                                <Alert
                                                    variant="destructive"
                                                    className="md:col-span-2"
                                                >
                                                    <AlertDescription>
                                                        {connection.lastError}
                                                    </AlertDescription>
                                                </Alert>
                                            )}
                                            {connection?.lastTestedAt && (
                                                <p className="text-xs text-muted-foreground md:col-span-2">
                                                    Last tested{' '}
                                                    {when(
                                                        connection.lastTestedAt,
                                                    )}
                                                    .
                                                </p>
                                            )}
                                        </>
                                    )}
                                </SettingsSection>
                            </CardContent>
                        </Card>

                        <section className="flex flex-col gap-4">
                            <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                                <div className="min-w-0 space-y-1">
                                    <h3 className="text-base font-medium">
                                        Locks
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        Link each lock to the room it opens.
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    {lockTotal > 10 && (
                                        <Input
                                            type="search"
                                            aria-label="Search locks"
                                            placeholder="Search lock or room"
                                            value={lockQuery}
                                            onChange={(event) =>
                                                setLockQuery(event.target.value)
                                            }
                                            className="w-56"
                                        />
                                    )}
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={
                                            !connection?.signedIn ||
                                            action.processing
                                        }
                                        focusableWhenDisabled
                                        onClick={() => post(sync.url())}
                                    >
                                        {action.processing ? (
                                            <Spinner />
                                        ) : (
                                            <RefreshCw />
                                        )}
                                        Sync locks from TTLock
                                    </Button>
                                </div>
                            </header>

                            {lockTotal === 0 ? (
                                <Card>
                                    <CardContent className="flex flex-col items-center gap-2 py-10 text-center">
                                        <Lock
                                            aria-hidden
                                            className="size-8 text-muted-foreground"
                                        />
                                        <p className="text-sm font-medium">
                                            No locks yet
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Sign in above, then sync your locks.
                                        </p>
                                    </CardContent>
                                </Card>
                            ) : (
                                <>
                                    <div className="overflow-hidden rounded-lg border">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Lock</TableHead>
                                                    <TableHead>Room</TableHead>
                                                    <TableHead>
                                                        Battery
                                                    </TableHead>
                                                    <TableHead>
                                                        Gateway
                                                    </TableHead>
                                                    <TableHead>Clock</TableHead>
                                                    <TableHead className="hidden lg:table-cell">
                                                        Checked
                                                    </TableHead>
                                                    <TableHead className="w-10">
                                                        <span className="sr-only">
                                                            Actions
                                                        </span>
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {locks.data.length === 0 ? (
                                                    <TableRow>
                                                        <TableCell
                                                            colSpan={7}
                                                            className="text-muted-foreground"
                                                        >
                                                            No lock matches "
                                                            {lockSearch}".
                                                        </TableCell>
                                                    </TableRow>
                                                ) : (
                                                    locks.data.map((lock) => (
                                                        <TableRow key={lock.id}>
                                                            <TableCell>
                                                                <div className="flex items-center gap-2 font-medium">
                                                                    <Lock
                                                                        aria-hidden
                                                                        className="size-4 shrink-0 text-muted-foreground"
                                                                    />
                                                                    <span className="truncate">
                                                                        {
                                                                            lock.name
                                                                        }
                                                                    </span>
                                                                </div>
                                                            </TableCell>
                                                            <TableCell className="w-40">
                                                                <EnumSelect
                                                                    aria-label={`Room for ${lock.name}`}
                                                                    options={
                                                                        rooms
                                                                    }
                                                                    value={
                                                                        lock.roomId ===
                                                                        null
                                                                            ? ''
                                                                            : String(
                                                                                  lock.roomId,
                                                                              )
                                                                    }
                                                                    allLabel="Not linked"
                                                                    onValueChange={(
                                                                        value,
                                                                    ) =>
                                                                        router.put(
                                                                            DoorLockSettingsController.link.url(
                                                                                {
                                                                                    doorLock:
                                                                                        lock.id,
                                                                                },
                                                                            ),
                                                                            {
                                                                                room_id:
                                                                                    value ===
                                                                                    ''
                                                                                        ? null
                                                                                        : Number(
                                                                                              value,
                                                                                          ),
                                                                            },
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </TableCell>
                                                            <LockHealthCells
                                                                lock={lock}
                                                            />
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
                                                                            disabled={
                                                                                action.processing
                                                                            }
                                                                            title={
                                                                                lock.hasGateway
                                                                                    ? undefined
                                                                                    : GATEWAY_NOTE
                                                                            }
                                                                            onClick={() =>
                                                                                post(
                                                                                    DoorLockSettingsController.unlock.url(
                                                                                        {
                                                                                            doorLock:
                                                                                                lock.id,
                                                                                        },
                                                                                    ),
                                                                                )
                                                                            }
                                                                        >
                                                                            <Unlock />
                                                                            Unlock
                                                                            now
                                                                        </DropdownMenuItem>
                                                                        <DropdownMenuItem
                                                                            disabled={
                                                                                action.processing
                                                                            }
                                                                            title={
                                                                                lock.hasGateway
                                                                                    ? undefined
                                                                                    : GATEWAY_NOTE
                                                                            }
                                                                            onClick={() =>
                                                                                post(
                                                                                    DoorLockSettingsController.clock.url(
                                                                                        {
                                                                                            doorLock:
                                                                                                lock.id,
                                                                                        },
                                                                                    ),
                                                                                )
                                                                            }
                                                                        >
                                                                            <Clock />
                                                                            Set
                                                                            clock
                                                                        </DropdownMenuItem>
                                                                        <DropdownMenuItem
                                                                            disabled={
                                                                                action.processing
                                                                            }
                                                                            onClick={() =>
                                                                                post(
                                                                                    DoorLockSettingsController.health.url(
                                                                                        {
                                                                                            doorLock:
                                                                                                lock.id,
                                                                                        },
                                                                                    ),
                                                                                )
                                                                            }
                                                                        >
                                                                            <Activity />
                                                                            Check
                                                                            health
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
                                            locks.meta,
                                            'ttlocks_page',
                                        )}
                                    />
                                </>
                            )}
                        </section>

                        <section className="flex flex-col gap-4">
                            <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                                <div className="min-w-0 space-y-1">
                                    <h3 className="text-base font-medium">
                                        PINs
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        Newest first. Read the PIN out to the
                                        guest.
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    disabled={lockOptions.length === 0}
                                    focusableWhenDisabled
                                    onClick={() => setSheetOpen(true)}
                                >
                                    <KeyRound />
                                    Issue a PIN
                                </Button>
                            </header>
                            {passcodes.meta.total === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No PINs yet.
                                </p>
                            ) : (
                                <>
                                    <div className="overflow-hidden rounded-lg border">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>For</TableHead>
                                                    <TableHead>Lock</TableHead>
                                                    <TableHead>PIN</TableHead>
                                                    <TableHead>Valid</TableHead>
                                                    <TableHead>
                                                        Status
                                                    </TableHead>
                                                    <TableHead className="text-right">
                                                        <span className="sr-only">
                                                            Actions
                                                        </span>
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {passcodes.data.map(
                                                    (passcode) => (
                                                        <TableRow
                                                            key={passcode.id}
                                                        >
                                                            <TableCell>
                                                                {passcode.label}
                                                                <span className="block text-xs text-muted-foreground">
                                                                    {
                                                                        passcode.kindLabel
                                                                    }
                                                                </span>
                                                            </TableCell>
                                                            <TableCell>
                                                                {
                                                                    passcode.lockName
                                                                }
                                                            </TableCell>
                                                            <TableCell className="font-mono tabular-nums">
                                                                {passcode.code ??
                                                                    '—'}
                                                            </TableCell>
                                                            <TableCell className="tabular-nums">
                                                                {when(
                                                                    passcode.startsAt,
                                                                )}{' '}
                                                                to{' '}
                                                                {when(
                                                                    passcode.endsAt,
                                                                )}
                                                            </TableCell>
                                                            <TableCell className="max-w-72 whitespace-normal">
                                                                <StatusBadge
                                                                    status={
                                                                        passcode.status
                                                                    }
                                                                />
                                                                {passcode.error && (
                                                                    <span
                                                                        title={
                                                                            passcode.error
                                                                        }
                                                                        className="mt-1 block text-xs break-words text-destructive"
                                                                    >
                                                                        {
                                                                            passcode.error
                                                                        }
                                                                    </span>
                                                                )}
                                                                {passcode.revokeNote && (
                                                                    <span
                                                                        title={
                                                                            passcode.revokeNote
                                                                        }
                                                                        className="mt-1 block text-xs break-words text-warning"
                                                                    >
                                                                        {
                                                                            passcode.revokeNote
                                                                        }
                                                                    </span>
                                                                )}
                                                            </TableCell>
                                                            <TableCell className="text-right whitespace-nowrap">
                                                                <div className="flex justify-end gap-2">
                                                                    {passcode.canRetry && (
                                                                        <Button
                                                                            type="button"
                                                                            variant="outline"
                                                                            size="sm"
                                                                            disabled={
                                                                                action.processing
                                                                            }
                                                                            focusableWhenDisabled
                                                                            onClick={() =>
                                                                                post(
                                                                                    DoorLockSettingsController.retry.url(
                                                                                        {
                                                                                            doorPasscode:
                                                                                                passcode.id,
                                                                                        },
                                                                                    ),
                                                                                )
                                                                            }
                                                                        >
                                                                            Retry
                                                                        </Button>
                                                                    )}
                                                                    {passcode.canRevoke && (
                                                                        <Button
                                                                            type="button"
                                                                            variant="outline"
                                                                            size="sm"
                                                                            disabled={
                                                                                action.processing
                                                                            }
                                                                            focusableWhenDisabled
                                                                            onClick={() =>
                                                                                post(
                                                                                    DoorLockSettingsController.revoke.url(
                                                                                        {
                                                                                            doorPasscode:
                                                                                                passcode.id,
                                                                                        },
                                                                                    ),
                                                                                )
                                                                            }
                                                                        >
                                                                            Revoke
                                                                        </Button>
                                                                    )}
                                                                </div>
                                                            </TableCell>
                                                        </TableRow>
                                                    ),
                                                )}
                                            </TableBody>
                                        </Table>
                                    </div>
                                    <PaginationFooter
                                        pagination={toPaginationData(
                                            passcodes.meta,
                                            'pins_page',
                                        )}
                                    />
                                </>
                            )}
                        </section>
                    </>
                )}
            </div>

            <FormOverlay
                variant="sheet"
                size="md"
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                title="Issue a PIN"
                description="For a guest, a cleaner or a contractor. Stay PINs are issued by check-in."
                errors={pin.errors}
                dirty={pin.isDirty}
                discardTitle="Discard this PIN?"
                onSubmit={issuePin}
                submitLabel="Issue PIN"
                processing={pin.processing}
                submitDisabled={needsGateway}
            >
                <FieldGroup>
                    <Field data-invalid={!!pinErrors.door_lock_id}>
                        <FieldLabel htmlFor="door_lock_id">Lock</FieldLabel>
                        <EnumSelect
                            id="door_lock_id"
                            name="door_lock_id"
                            options={lockOptions}
                            value={pin.data.door_lock_id}
                            onValueChange={(value) =>
                                pin.setData('door_lock_id', value)
                            }
                        />
                        <FieldError>{pinErrors.door_lock_id}</FieldError>
                    </Field>
                    <Field data-invalid={!!pinErrors.kind}>
                        <FieldLabel htmlFor="kind">Kind of PIN</FieldLabel>
                        <EnumSelect
                            id="kind"
                            name="kind"
                            options={passcodeKinds}
                            value={pin.data.kind}
                            onValueChange={(value) =>
                                pin.setData('kind', value)
                            }
                        />
                        <FieldError>{pinErrors.kind}</FieldError>
                    </Field>
                    {needsGateway && (
                        <Alert variant="destructive">
                            <AlertDescription>{GATEWAY_NOTE}</AlertDescription>
                        </Alert>
                    )}
                    <Field data-invalid={!!pinErrors.label}>
                        <FieldLabel htmlFor="label">Who is it for</FieldLabel>
                        <Input
                            id="label"
                            value={pin.data.label}
                            onChange={(event) =>
                                pin.setData('label', event.target.value)
                            }
                            aria-invalid={!!pinErrors.label}
                        />
                        <FieldError>{pinErrors.label}</FieldError>
                    </Field>
                    {pin.data.kind === 'custom' && (
                        <Field data-invalid={!!pinErrors.code}>
                            <FieldLabel htmlFor="code">PIN</FieldLabel>
                            <Input
                                id="code"
                                inputMode="numeric"
                                placeholder="4 to 9 digits, blank for a random one"
                                value={pin.data.code}
                                onChange={(event) =>
                                    pin.setData('code', event.target.value)
                                }
                                aria-invalid={!!pinErrors.code}
                            />
                            <FieldError>{pinErrors.code}</FieldError>
                        </Field>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field data-invalid={!!pinErrors.starts_at}>
                            <FieldLabel htmlFor="starts_at">From</FieldLabel>
                            <Input
                                id="starts_at"
                                type="datetime-local"
                                value={pin.data.starts_at}
                                onChange={(event) =>
                                    pin.setData('starts_at', event.target.value)
                                }
                                aria-invalid={!!pinErrors.starts_at}
                            />
                            <FieldError>{pinErrors.starts_at}</FieldError>
                        </Field>
                        <Field data-invalid={!!pinErrors.ends_at}>
                            <FieldLabel htmlFor="ends_at">Until</FieldLabel>
                            <Input
                                id="ends_at"
                                type="datetime-local"
                                value={pin.data.ends_at}
                                onChange={(event) =>
                                    pin.setData('ends_at', event.target.value)
                                }
                                aria-invalid={!!pinErrors.ends_at}
                            />
                            <FieldError>{pinErrors.ends_at}</FieldError>
                        </Field>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {pin.data.kind === 'generated'
                            ? 'A Generated PIN is valid by the whole hour: From is rounded down and Until is rounded up, so 09:53 to 09:55 becomes 09:00 to 10:00. Choose a Custom PIN for exact minutes.'
                            : 'A Custom PIN keeps the exact minutes.'}
                    </p>
                </FieldGroup>
            </FormOverlay>
        </>
    );
}

DoorLocksIndex.layout = {
    breadcrumbs: [
        { title: 'Settings', href: profile() },
        { title: 'Door locks', href: edit() },
    ],
};
