import { router, useForm } from '@inertiajs/react';
import { Monitor, Plus, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { PosVoidReasonForm } from '@/components/pms/pos/pos-void-reason-form';
import type { VoidReasonRow } from '@/components/pms/pos/pos-void-reason-form';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    revoke as revokeDevice,
    store as storeDevice,
} from '@/routes/settings/pos/devices';
import {
    invite as invitePin,
    revoke as revokePin,
} from '@/routes/settings/pos/staff-pins';

type Props = {
    staff: { id: number; name: string; hasPin: boolean }[];
    devices: {
        id: number;
        name: string;
        outlet: string;
        revoked: boolean;
        lastSeenAt: string | null;
    }[];
    outlets: EnumOption[];
    voidReasons: VoidReasonRow[];
};

export default function PosStaffSettings({
    staff,
    devices,
    outlets,
    voidReasons,
}: Props) {
    const [device, setDevice] = useState(false);
    const [reason, setReason] = useState<{ row: VoidReasonRow | null } | null>(
        null,
    );
    const [confirmRevoke, setConfirmRevoke] = useState<{
        title: string;
        description: string;
        url: string;
    } | null>(null);
    const deviceForm = useForm({ name: '', pos_outlet_id: '' });

    return (
        <PosSettingsShell activeTab="staff">
            <div className="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Staff PINs</CardTitle>
                        <CardDescription>
                            Staff set their own PIN under Account. Invite
                            someone who has none to set it by email, or revoke a
                            PIN that is known to others.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>PIN</TableHead>
                                    <TableHead className="w-24">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {staff.map((person) => (
                                    <TableRow key={person.id}>
                                        <TableCell className="font-medium">
                                            {person.name}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    person.hasPin
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {person.hasPin
                                                    ? 'Set'
                                                    : 'Not set'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {!person.hasPin && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            invitePin.url({
                                                                user: person.id,
                                                            }),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Invite
                                                </Button>
                                            )}
                                            {person.hasPin && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setConfirmRevoke({
                                                            title: `Revoke ${person.name}'s PIN?`,
                                                            description:
                                                                'They will be signed out of Terminal Mode and must set a new PIN under Account.',
                                                            url: revokePin.url({
                                                                user: person.id,
                                                            }),
                                                        })
                                                    }
                                                >
                                                    Revoke
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="flex flex-col gap-1">
                            <CardTitle>Terminals</CardTitle>
                            <CardDescription>
                                Register this browser as a till to let staff
                                sign in with a PIN.
                            </CardDescription>
                        </div>
                        <Button onClick={() => setDevice(true)}>
                            <Plus data-icon="inline-start" />
                            Register this browser
                        </Button>
                    </CardHeader>
                    <CardContent>
                        {devices.length === 0 ? (
                            <EmptyState
                                icon={Monitor}
                                title="No registered terminals"
                                description="Register a till browser here before entering Terminal Mode."
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Outlet</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-24">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {devices.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell className="font-medium">
                                                {row.name}
                                            </TableCell>
                                            <TableCell>{row.outlet}</TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        row.revoked
                                                            ? 'outline'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {row.revoked
                                                        ? 'Revoked'
                                                        : 'Active'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {!row.revoked && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setConfirmRevoke({
                                                                title: `Revoke terminal ${row.name}?`,
                                                                description:
                                                                    'Staff will no longer be able to sign in with a PIN on this browser.',
                                                                url: revokeDevice.url(
                                                                    {
                                                                        device: row.id,
                                                                    },
                                                                ),
                                                            })
                                                        }
                                                    >
                                                        Revoke
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="flex flex-col gap-1">
                            <CardTitle>Void reasons</CardTitle>
                            <CardDescription>
                                What a cashier picks when a sent line or an
                                order is voided.
                            </CardDescription>
                        </div>
                        <Button onClick={() => setReason({ row: null })}>
                            <Plus data-icon="inline-start" />
                            Add reason
                        </Button>
                    </CardHeader>
                    <CardContent>
                        {voidReasons.length === 0 ? (
                            <EmptyState
                                icon={ShieldAlert}
                                title="No void reasons yet"
                                description="Add reasons such as Kitchen error, Guest changed mind, or Out of stock."
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Reason</TableHead>
                                        <TableHead>Manager PIN</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-20">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {voidReasons.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell className="font-medium">
                                                {row.label}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {row.requires_manager_pin
                                                    ? 'Required'
                                                    : 'Optional'}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        row.active
                                                            ? 'secondary'
                                                            : 'outline'
                                                    }
                                                >
                                                    {row.active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setReason({ row })
                                                    }
                                                >
                                                    Edit
                                                </Button>
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
                variant="dialog"
                open={device}
                onOpenChange={setDevice}
                title="Register this browser"
                description="Give the till a name and pick its outlet."
                errors={deviceForm.errors}
                processing={deviceForm.processing}
                submitLabel="Register"
                onSubmit={() =>
                    deviceForm.post(storeDevice.url(), {
                        preserveScroll: true,
                        onSuccess: () => setDevice(false),
                    })
                }
            >
                <Field data-invalid={!!deviceForm.errors.name}>
                    <FieldLabel htmlFor="device-name">Name</FieldLabel>
                    <Input
                        id="device-name"
                        maxLength={80}
                        value={deviceForm.data.name}
                        onChange={(event) =>
                            deviceForm.setData('name', event.target.value)
                        }
                        autoFocus
                    />
                    <FieldError>{deviceForm.errors.name}</FieldError>
                </Field>
                <Field data-invalid={!!deviceForm.errors.pos_outlet_id}>
                    <FieldLabel htmlFor="device-outlet">Outlet</FieldLabel>
                    <EnumSelect
                        id="device-outlet"
                        name="pos_outlet_id"
                        options={outlets}
                        value={deviceForm.data.pos_outlet_id}
                        onValueChange={(value) =>
                            deviceForm.setData('pos_outlet_id', value)
                        }
                        placeholder="Pick an outlet"
                    />
                    <FieldError>{deviceForm.errors.pos_outlet_id}</FieldError>
                </Field>
            </FormOverlay>

            {reason && (
                <PosVoidReasonForm
                    key={reason.row?.id ?? 'new'}
                    row={reason.row}
                    onClose={() => setReason(null)}
                />
            )}

            <AlertDialog
                open={confirmRevoke !== null}
                onOpenChange={(open) => !open && setConfirmRevoke(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {confirmRevoke?.title}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {confirmRevoke?.description}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            onClick={() => {
                                if (confirmRevoke !== null) {
                                    router.post(
                                        confirmRevoke.url,
                                        {},
                                        {
                                            preserveScroll: true,
                                            onFinish: () =>
                                                setConfirmRevoke(null),
                                        },
                                    );
                                }
                            }}
                        >
                            Revoke
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </PosSettingsShell>
    );
}

PosStaffSettings.layout = {
    breadcrumbs: posSettingsBreadcrumbs('staff'),
};
