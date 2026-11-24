import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { revoke as revokePin } from '@/routes/settings/pos/staff-pins';
import {
    revoke as revokeDevice,
    store as storeDevice,
} from '@/routes/settings/pos/devices';
import {
    store as storeReason,
    update as updateReason,
} from '@/routes/settings/pos/void-reasons';

type Reason = {
    id: number;
    label: string;
    requires_manager_pin: boolean;
    default_return_stock: boolean;
    active: boolean;
};

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
    voidReasons: Reason[];
};

export default function PosStaffSettings({
    staff,
    devices,
    outlets,
    voidReasons,
}: Props) {
    const [device, setDevice] = useState(false);
    const [reason, setReason] = useState<{ row: Reason | null } | null>(null);
    const deviceForm = useForm({ name: '', pos_outlet_id: '' });

    return (
        <PosSettingsShell activeTab="staff">
            <div className="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Staff PINs</CardTitle>
                        <CardDescription>
                            Staff set their own PIN under Account. Revoke one if
                            a PIN is known to others.
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
                                            <Badge variant="secondary">
                                                {person.hasPin
                                                    ? 'Set'
                                                    : 'Not set'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {person.hasPin && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            revokePin.url({
                                                                user: person.id,
                                                            }),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
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
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Outlet</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="w-24">
                                        <span className="sr-only">Actions</span>
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
                                            <Badge variant="secondary">
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
                                                        router.post(
                                                            revokeDevice.url({
                                                                device: row.id,
                                                            }),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
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
                        <Table>
                            <TableBody>
                                {voidReasons.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="font-medium">
                                            {row.label}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="secondary">
                                                {row.active
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="w-20">
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
                </Field>
            </FormOverlay>
            {reason && (
                <ReasonForm
                    key={reason.row?.id ?? 'new'}
                    row={reason.row}
                    onClose={() => setReason(null)}
                />
            )}
        </PosSettingsShell>
    );
}

function ReasonForm({
    row,
    onClose,
}: {
    row: Reason | null;
    onClose: () => void;
}) {
    const form = useForm({
        label: row?.label ?? '',
        requires_manager_pin: row?.requires_manager_pin ?? true,
        default_return_stock: row?.default_return_stock ?? false,
        active: row?.active ?? true,
        sort_order: 0,
    });

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? 'Edit void reason' : 'Add void reason'}
            errors={form.errors}
            processing={form.processing}
            submitLabel="Save reason"
            onSubmit={() => {
                const options = { preserveScroll: true, onSuccess: onClose };

                if (row) {
                    form.put(updateReason.url({ reason: row.id }), options);
                } else {
                    form.post(storeReason.url(), options);
                }
            }}
        >
            <Field data-invalid={!!form.errors.label}>
                <FieldLabel htmlFor="reason-label">Reason</FieldLabel>
                <Input
                    id="reason-label"
                    maxLength={80}
                    value={form.data.label}
                    onChange={(event) =>
                        form.setData('label', event.target.value)
                    }
                    autoFocus
                />
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="reason-pin"
                    checked={form.data.requires_manager_pin}
                    onCheckedChange={(checked) =>
                        form.setData('requires_manager_pin', checked)
                    }
                />
                <FieldLabel htmlFor="reason-pin">Needs a manager</FieldLabel>
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="reason-stock"
                    checked={form.data.default_return_stock}
                    onCheckedChange={(checked) =>
                        form.setData('default_return_stock', checked)
                    }
                />
                <FieldLabel htmlFor="reason-stock">
                    Put the stock back by default
                </FieldLabel>
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="reason-active"
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                />
                <FieldLabel htmlFor="reason-active">Active</FieldLabel>
            </Field>
        </FormOverlay>
    );
}

PosStaffSettings.layout = {
    breadcrumbs: posSettingsBreadcrumbs('staff'),
};
