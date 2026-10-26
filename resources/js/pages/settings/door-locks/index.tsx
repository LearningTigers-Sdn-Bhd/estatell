import { Head, router, useForm } from '@inertiajs/react';
import {
    BatteryMedium,
    CircleCheck,
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
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
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
    const [sheetOpen, setSheetOpen] = useState(false);
    const action = useForm({});
    const pin = useForm({
        door_lock_id: locks[0] ? String(locks[0].id) : '',
        kind: passcodeKinds[0]?.value ?? 'generated',
        label: '',
        code: '',
        starts_at: '',
        ends_at: '',
    });
    const pinErrors = pin.errors as Record<string, string | undefined>;

    const selectedLock = locks.find(
        (lock) => String(lock.id) === pin.data.door_lock_id,
    );
    const needsGateway =
        pin.data.kind === 'custom' && selectedLock?.hasGateway === false;
    const anyWithoutGateway = locks.some((lock) => !lock.hasGateway);

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
                                        value={
                                            connection?.passcodeMode ??
                                            'generated'
                                        }
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
                                            Last tested{' '}
                                            {when(connection.lastTestedAt)}.
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
                            <h3 className="text-base font-medium">Locks</h3>
                            <p className="text-sm text-muted-foreground">
                                Link each lock to the room it opens.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={
                                !connection?.signedIn || action.processing
                            }
                            focusableWhenDisabled
                            onClick={() => post(sync.url())}
                        >
                            {action.processing ? <Spinner /> : <RefreshCw />}
                            Sync locks from TTLock
                        </Button>
                    </header>

                    {locks.length === 0 ? (
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
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {locks.map((lock) => (
                                <Card key={lock.id}>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2">
                                            <Lock
                                                aria-hidden
                                                className="size-4 text-muted-foreground"
                                            />
                                            {lock.name}
                                        </CardTitle>
                                        <CardAction>
                                            {lock.hasGateway ? (
                                                <Badge variant="secondary">
                                                    Gateway
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline">
                                                    No gateway
                                                </Badge>
                                            )}
                                        </CardAction>
                                        <CardDescription className="flex items-center gap-1 tabular-nums">
                                            <BatteryMedium
                                                aria-hidden
                                                className="size-4"
                                            />
                                            {lock.batteryPercent === null
                                                ? 'Battery unknown'
                                                : `${lock.batteryPercent}% battery`}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="flex flex-col gap-4">
                                        <Field>
                                            <FieldLabel>Room</FieldLabel>
                                            <EnumSelect
                                                aria-label={`Room for ${lock.name}`}
                                                options={rooms}
                                                value={
                                                    lock.roomId === null
                                                        ? ''
                                                        : String(lock.roomId)
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
                                        </Field>
                                        <div className="flex flex-wrap gap-2">
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
                                                Unlock now
                                            </Button>
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
                                                Set clock
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    )}
                </section>

                <Card>
                    <CardHeader>
                        <CardTitle>Latest PINs</CardTitle>
                        <CardDescription>
                            The last 50. Read the PIN out to the guest.
                        </CardDescription>
                        <CardAction>
                            <Button
                                type="button"
                                disabled={locks.length === 0}
                                focusableWhenDisabled
                                onClick={() => setSheetOpen(true)}
                            >
                                <KeyRound />
                                Issue a PIN
                            </Button>
                        </CardAction>
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
                                                {passcode.revokeNote && (
                                                    <span className="mt-1 block max-w-xs text-xs text-warning">
                                                        {passcode.revokeNote}
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
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
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
                            options={locks.map((lock) => ({
                                value: String(lock.id),
                                label: lock.name,
                            }))}
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
