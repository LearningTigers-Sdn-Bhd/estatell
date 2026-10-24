import { Form, Head, router, useForm } from '@inertiajs/react';
import {
    BatteryMedium,
    Clock,
    KeyRound,
    Lock,
    Plug,
    RefreshCw,
    Unlock,
} from 'lucide-react';
import { useState } from 'react';
import DoorLockSettingsController from '@/actions/App/Http/Controllers/DoorLocks/DoorLockSettingsController';
import { EnumSelect } from '@/components/pms/enum-select';
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
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
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

export default function DoorLocksIndex({
    connection,
    passcodeModes,
    passcodeKinds,
    locks,
    rooms,
    passcodes,
}: {
    connection: App.Data.DoorLocks.DoorLockConnectionData | null;
    passcodeModes: { value: string; label: string }[];
    passcodeKinds: { value: string; label: string }[];
    locks: App.Data.DoorLocks.DoorLockData[];
    rooms: { value: string; label: string }[];
    passcodes: App.Data.DoorLocks.DoorPasscodeData[];
}) {
    const [lockId, setLockId] = useState(locks[0] ? String(locks[0].id) : '');
    const [kind, setKind] = useState(passcodeKinds[0]?.value ?? 'generated');
    const action = useForm({});

    const selectedLock = locks.find((lock) => String(lock.id) === lockId);
    const needsGateway =
        kind === 'custom' && selectedLock?.hasGateway === false;
    const anyWithoutGateway = locks.some((lock) => !lock.hasGateway);

    return (
        <>
            <Head title="Door locks" />
            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Door locks"
                    description="Give each checked-in stay a PIN for its room door, and take it away at check-out."
                />

                {connection?.signedIn && anyWithoutGateway && (
                    <Alert>
                        <AlertTitle>Some locks have no gateway</AlertTitle>
                        <AlertDescription>
                            {GATEWAY_NOTE} Custom PINs, remote unlock and
                            removing a PIN before it ends need a TTLock gateway
                            installed within range of the lock.
                        </AlertDescription>
                    </Alert>
                )}

                <SettingsSection
                    guardUnsavedChanges
                    form={DoorLockSettingsController.update.form()}
                    title="TTLock account"
                    description="Sign in with the TTLock app account that owns the locks. The password is used once and is not saved."
                    submitLabel="Save connection"
                    contentClassName="grid gap-5 md:grid-cols-2"
                    actions={
                        <Button
                            type="button"
                            variant="outline"
                            disabled={connection === null || action.processing}
                            focusableWhenDisabled
                            onClick={() =>
                                action.post(testConnection.url(), {
                                    preserveScroll: true,
                                })
                            }
                        >
                            {action.processing ? <Spinner /> : <Plug />}
                            Test connection
                        </Button>
                    }
                >
                    {(errors) => (
                        <>
                            <SettingsInput
                                name="ttlock_username"
                                label="TTLock username"
                                value={connection?.ttlockUsername ?? ''}
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
                                value={connection?.passcodeMode ?? 'generated'}
                                options={passcodeModes}
                                description="Custom PINs fall back to generated ones on a lock without a gateway."
                                error={errors.passcode_mode}
                            />
                            <SettingsSwitch
                                bordered
                                name="enabled"
                                label="Issue PINs automatically"
                                checked={connection?.enabled ?? false}
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
                                    Last tested {when(connection.lastTestedAt)}.
                                </p>
                            )}
                        </>
                    )}
                </SettingsSection>

                <Card>
                    <CardHeader>
                        <CardTitle>Locks</CardTitle>
                        <CardDescription>
                            Link each lock to the room it opens.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={
                                    !connection?.signedIn || action.processing
                                }
                                focusableWhenDisabled
                                onClick={() =>
                                    action.post(sync.url(), {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                {action.processing ? (
                                    <Spinner />
                                ) : (
                                    <RefreshCw />
                                )}
                                Sync locks from TTLock
                            </Button>
                        </div>
                        {locks.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No locks yet. Sign in, then sync.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Lock</TableHead>
                                        <TableHead>Battery</TableHead>
                                        <TableHead>Gateway</TableHead>
                                        <TableHead>Room</TableHead>
                                        <TableHead className="text-right">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {locks.map((lock) => (
                                        <TableRow key={lock.id}>
                                            <TableCell className="font-medium">
                                                <span className="flex items-center gap-2">
                                                    <Lock
                                                        aria-hidden
                                                        className="size-4 text-muted-foreground"
                                                    />
                                                    {lock.name}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <span className="flex items-center gap-1 tabular-nums">
                                                    <BatteryMedium
                                                        aria-hidden
                                                        className="size-4 text-muted-foreground"
                                                    />
                                                    {lock.batteryPercent ===
                                                    null
                                                        ? '—'
                                                        : `${lock.batteryPercent}%`}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                {lock.hasGateway ? (
                                                    <Badge variant="secondary">
                                                        Gateway
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="outline">
                                                        No gateway
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell className="w-48">
                                                <EnumSelect
                                                    aria-label={`Room for ${lock.name}`}
                                                    options={rooms}
                                                    value={
                                                        lock.roomId === null
                                                            ? ''
                                                            : String(
                                                                  lock.roomId,
                                                              )
                                                    }
                                                    allLabel="Not linked"
                                                    onValueChange={(value) =>
                                                        router.put(
                                                            DoorLockSettingsController.link.url(
                                                                {
                                                                    doorLock:
                                                                        lock.id,
                                                                },
                                                            ),
                                                            {
                                                                room_id:
                                                                    value === ''
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
                                            <TableCell className="text-right">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={action.processing}
                                                    focusableWhenDisabled
                                                    title={
                                                        lock.hasGateway
                                                            ? undefined
                                                            : GATEWAY_NOTE
                                                    }
                                                    onClick={() =>
                                                        action.post(
                                                            DoorLockSettingsController.unlock.url(
                                                                {
                                                                    doorLock:
                                                                        lock.id,
                                                                },
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Unlock />
                                                    Unlock now
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    className="ml-2"
                                                    disabled={action.processing}
                                                    focusableWhenDisabled
                                                    title={
                                                        lock.hasGateway
                                                            ? undefined
                                                            : GATEWAY_NOTE
                                                    }
                                                    onClick={() =>
                                                        action.post(
                                                            DoorLockSettingsController.clock.url(
                                                                {
                                                                    doorLock:
                                                                        lock.id,
                                                                },
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Clock />
                                                    Set clock
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Issue a PIN</CardTitle>
                        <CardDescription>
                            For a guest, a cleaner or a contractor. Stay PINs
                            are issued by check-in.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...DoorLockSettingsController.storePasscode.form()}
                            options={{ preserveScroll: true }}
                            className="grid gap-5 md:grid-cols-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field>
                                        <FieldLabel htmlFor="door_lock_id">
                                            Lock
                                        </FieldLabel>
                                        <EnumSelect
                                            id="door_lock_id"
                                            name="door_lock_id"
                                            options={locks.map((lock) => ({
                                                value: String(lock.id),
                                                label: lock.name,
                                            }))}
                                            value={lockId}
                                            onValueChange={setLockId}
                                        />
                                        <FieldError>
                                            {errors.door_lock_id}
                                        </FieldError>
                                    </Field>
                                    <Field>
                                        <FieldLabel htmlFor="kind">
                                            Kind of PIN
                                        </FieldLabel>
                                        <EnumSelect
                                            id="kind"
                                            name="kind"
                                            options={passcodeKinds}
                                            value={kind}
                                            onValueChange={setKind}
                                        />
                                        <FieldError>{errors.kind}</FieldError>
                                    </Field>
                                    {needsGateway && (
                                        <Alert
                                            variant="destructive"
                                            className="md:col-span-2"
                                        >
                                            <AlertDescription>
                                                {GATEWAY_NOTE}
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                    <SettingsInput
                                        name="label"
                                        label="Who is it for"
                                        value=""
                                        error={errors.label}
                                    />
                                    {kind === 'custom' && (
                                        <SettingsInput
                                            name="code"
                                            label="PIN"
                                            value=""
                                            placeholder="4 to 9 digits, blank for a random one"
                                            error={errors.code}
                                        />
                                    )}
                                    <Field>
                                        <FieldLabel htmlFor="starts_at">
                                            From
                                        </FieldLabel>
                                        <Input
                                            id="starts_at"
                                            name="starts_at"
                                            type="datetime-local"
                                            aria-invalid={!!errors.starts_at}
                                        />
                                        <FieldError>
                                            {errors.starts_at}
                                        </FieldError>
                                    </Field>
                                    <Field>
                                        <FieldLabel htmlFor="ends_at">
                                            Until
                                        </FieldLabel>
                                        <Input
                                            id="ends_at"
                                            name="ends_at"
                                            type="datetime-local"
                                            aria-invalid={!!errors.ends_at}
                                        />
                                        <FieldError>
                                            {errors.ends_at}
                                        </FieldError>
                                    </Field>
                                    <div className="md:col-span-2">
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing ||
                                                locks.length === 0 ||
                                                needsGateway
                                            }
                                            focusableWhenDisabled
                                        >
                                            {processing ? (
                                                <Spinner />
                                            ) : (
                                                <KeyRound />
                                            )}
                                            Issue PIN
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Latest PINs</CardTitle>
                        <CardDescription>
                            The last 50. Read the PIN out to the guest.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {passcodes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No PINs yet.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>For</TableHead>
                                        <TableHead>Lock</TableHead>
                                        <TableHead>PIN</TableHead>
                                        <TableHead>Valid</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {passcodes.map((passcode) => (
                                        <TableRow key={passcode.id}>
                                            <TableCell>
                                                {passcode.label}
                                                <span className="block text-xs text-muted-foreground">
                                                    {passcode.kindLabel}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                {passcode.lockName}
                                            </TableCell>
                                            <TableCell className="font-mono tabular-nums">
                                                {passcode.code ?? '—'}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {when(passcode.startsAt)} to{' '}
                                                {when(passcode.endsAt)}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={passcode.status}
                                                />
                                                {passcode.error && (
                                                    <span className="mt-1 block text-xs text-destructive">
                                                        {passcode.error}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
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
                                                                action.post(
                                                                    DoorLockSettingsController.retry.url(
                                                                        {
                                                                            doorPasscode:
                                                                                passcode.id,
                                                                        },
                                                                    ),
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
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
                                                                action.post(
                                                                    DoorLockSettingsController.revoke.url(
                                                                        {
                                                                            doorPasscode:
                                                                                passcode.id,
                                                                        },
                                                                    ),
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Revoke
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

DoorLocksIndex.layout = {
    breadcrumbs: [
        { title: 'Settings', href: profile() },
        { title: 'Door locks', href: edit() },
    ],
};
