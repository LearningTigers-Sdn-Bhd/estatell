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
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
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
import {
    index as outletsIndex,
    update as updateOutlet,
} from '@/routes/settings/pos/outlets';
import {
    store as storeRegister,
    update as updateRegister,
} from '@/routes/settings/pos/registers';

type Outlet = {
    id: number;
    name: string;
    serviceChargeTransactionCodeId: number | null;
    packagingTransactionCodeId: number | null;
    roundingTransactionCodeId: number | null;
    cashVarianceTransactionCodeId: number | null;
    safeClearingTransactionCodeId: number | null;
    sstRegistrationNumber: string | null;
    operationMode: string;
    roundingMode: string;
    roundingPolicy: string;
    roomChargeLimit: string | null;
    requireRoomChargeSignature: boolean;
    requireMealRedemptionConfirmation: boolean;
    requireReprintPin: boolean;
    requireDualControl: boolean;
    printReceiptAfterPayment: boolean;
    printDrawerSlip: boolean;
    requireClockIn: boolean;
};

type Props = {
    outlets: EnumOption[];
    outlet: Outlet | null;
    registers: { id: number; name: string; active: boolean }[];
    transactionCodes: EnumOption[];
    operationModes: EnumOption[];
    roundingModes: EnumOption[];
    roundingPolicies: EnumOption[];
};

const CODE_FIELDS = [
    [
        'service_charge_transaction_code_id',
        'Service charge account',
        'serviceChargeTransactionCodeId',
    ],
    [
        'packaging_transaction_code_id',
        'Packaging fee account',
        'packagingTransactionCodeId',
    ],
    [
        'rounding_transaction_code_id',
        'Cash rounding account',
        'roundingTransactionCodeId',
    ],
    [
        'cash_variance_transaction_code_id',
        'Cash short or over account',
        'cashVarianceTransactionCodeId',
    ],
    [
        'safe_clearing_transaction_code_id',
        'Safe clearing account',
        'safeClearingTransactionCodeId',
    ],
] as const;

const SWITCHES = [
    [
        'require_room_charge_signature',
        'Guest signs a room charge slip',
        'requireRoomChargeSignature',
    ],
    [
        'require_meal_redemption_confirmation',
        'Guest confirms an included meal',
        'requireMealRedemptionConfirmation',
    ],
    [
        'require_reprint_pin',
        'A manager approves a pre-bill reprint',
        'requireReprintPin',
    ],
    [
        'require_dual_control',
        'A different person must approve (dual control)',
        'requireDualControl',
    ],
    [
        'print_receipt_after_payment',
        'Offer the receipt after payment',
        'printReceiptAfterPayment',
    ],
    ['print_drawer_slip', 'Print a drawer slip', 'printDrawerSlip'],
    [
        'require_clock_in',
        'Staff must clock in to take orders',
        'requireClockIn',
    ],
] as const;

export default function PosNativeOutlets({
    outlets,
    outlet,
    registers,
    transactionCodes,
    operationModes,
    roundingModes,
    roundingPolicies,
}: Props) {
    return (
        <PosSettingsShell activeTab="registers">
            <div className="flex flex-col gap-6">
                {outlets.length > 1 && (
                    <EnumSelect
                        options={outlets}
                        value={String(outlet?.id ?? '')}
                        onValueChange={(value) =>
                            router.get(
                                outletsIndex.url(undefined, {
                                    query: { outlet: value },
                                }),
                            )
                        }
                        aria-label="Outlet"
                    />
                )}
                {outlet === null ? (
                    <p className="text-sm text-muted-foreground">
                        Enable POS on an outlet on the Outlets tab
                        first.
                    </p>
                ) : (
                    <>
                        <OutletForm
                            key={outlet.id}
                            outlet={outlet}
                            codes={transactionCodes}
                            operationModes={operationModes}
                            roundingModes={roundingModes}
                            roundingPolicies={roundingPolicies}
                        />
                        <Registers outletId={outlet.id} registers={registers} />
                    </>
                )}
            </div>
        </PosSettingsShell>
    );
}

function OutletForm({
    outlet,
    codes,
    operationModes,
    roundingModes,
    roundingPolicies,
}: {
    outlet: Outlet;
    codes: EnumOption[];
    operationModes: EnumOption[];
    roundingModes: EnumOption[];
    roundingPolicies: EnumOption[];
}) {
    const form = useForm({
        service_charge_transaction_code_id: String(
            outlet.serviceChargeTransactionCodeId ?? '',
        ),
        packaging_transaction_code_id: String(
            outlet.packagingTransactionCodeId ?? '',
        ),
        rounding_transaction_code_id: String(
            outlet.roundingTransactionCodeId ?? '',
        ),
        cash_variance_transaction_code_id: String(
            outlet.cashVarianceTransactionCodeId ?? '',
        ),
        safe_clearing_transaction_code_id: String(
            outlet.safeClearingTransactionCodeId ?? '',
        ),
        sst_registration_number: outlet.sstRegistrationNumber ?? '',
        operation_mode: outlet.operationMode,
        rounding_mode: outlet.roundingMode,
        rounding_policy: outlet.roundingPolicy,
        room_charge_limit: outlet.roomChargeLimit ?? '',
        require_room_charge_signature: outlet.requireRoomChargeSignature,
        require_meal_redemption_confirmation:
            outlet.requireMealRedemptionConfirmation,
        require_reprint_pin: outlet.requireReprintPin,
        require_dual_control: outlet.requireDualControl,
        print_receipt_after_payment: outlet.printReceiptAfterPayment,
        print_drawer_slip: outlet.printDrawerSlip,
        require_clock_in: outlet.requireClockIn,
    });

    return (
        <Card>
            <CardHeader>
                <CardTitle>{outlet.name}</CardTitle>
                <CardDescription>
                    Accounts for the daily journal, cash rounding and till
                    controls.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            service_charge_transaction_code_id:
                                data.service_charge_transaction_code_id || null,
                            packaging_transaction_code_id:
                                data.packaging_transaction_code_id || null,
                            rounding_transaction_code_id:
                                data.rounding_transaction_code_id || null,
                            cash_variance_transaction_code_id:
                                data.cash_variance_transaction_code_id || null,
                            safe_clearing_transaction_code_id:
                                data.safe_clearing_transaction_code_id || null,
                            sst_registration_number:
                                data.sst_registration_number || null,
                            room_charge_limit: data.room_charge_limit || null,
                        }));
                        form.put(updateOutlet.url({ outlet: outlet.id }), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        {CODE_FIELDS.map(([name, label]) => (
                            <Field
                                key={name}
                                data-invalid={!!form.errors[name]}
                            >
                                <FieldLabel htmlFor={name}>{label}</FieldLabel>
                                <EnumSelect
                                    id={name}
                                    name={name}
                                    options={codes}
                                    value={form.data[name]}
                                    onValueChange={(value) =>
                                        form.setData(name, value)
                                    }
                                    placeholder="Pick an account"
                                />
                            </Field>
                        ))}
                        <Field>
                            <FieldLabel htmlFor="operation_mode">
                                Service style
                            </FieldLabel>
                            <EnumSelect
                                id="operation_mode"
                                name="operation_mode"
                                options={operationModes}
                                value={form.data.operation_mode}
                                onValueChange={(value) =>
                                    form.setData('operation_mode', value)
                                }
                            />
                        </Field>
                        <Field>
                            <FieldLabel htmlFor="rounding_mode">
                                Cash rounding
                            </FieldLabel>
                            <EnumSelect
                                id="rounding_mode"
                                name="rounding_mode"
                                options={roundingModes}
                                value={form.data.rounding_mode}
                                onValueChange={(value) =>
                                    form.setData('rounding_mode', value)
                                }
                            />
                        </Field>
                        <Field>
                            <FieldLabel htmlFor="rounding_policy">
                                Rounds
                            </FieldLabel>
                            <EnumSelect
                                id="rounding_policy"
                                name="rounding_policy"
                                options={roundingPolicies}
                                value={form.data.rounding_policy}
                                onValueChange={(value) =>
                                    form.setData('rounding_policy', value)
                                }
                            />
                        </Field>
                        <Field>
                            <FieldLabel htmlFor="sst">
                                SST registration number
                            </FieldLabel>
                            <Input
                                id="sst"
                                value={form.data.sst_registration_number}
                                onChange={(event) =>
                                    form.setData(
                                        'sst_registration_number',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field data-invalid={!!form.errors.room_charge_limit}>
                            <FieldLabel htmlFor="limit">
                                Room charge limit
                            </FieldLabel>
                            <Input
                                id="limit"
                                inputMode="decimal"
                                className="tabular-nums"
                                value={form.data.room_charge_limit}
                                onChange={(event) =>
                                    form.setData(
                                        'room_charge_limit',
                                        event.target.value,
                                    )
                                }
                            />
                            <FieldDescription>
                                A bill above this needs a manager. Leave empty
                                for no limit.
                            </FieldDescription>
                        </Field>
                    </div>
                    <div className="flex flex-col gap-3">
                        {SWITCHES.map(([name, label]) => (
                            <Field key={name} orientation="horizontal">
                                <Switch
                                    id={name}
                                    checked={form.data[name]}
                                    onCheckedChange={(checked) =>
                                        form.setData(name, checked)
                                    }
                                />
                                <FieldLabel htmlFor={name}>{label}</FieldLabel>
                            </Field>
                        ))}
                    </div>
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Save outlet settings
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function Registers({
    outletId,
    registers,
}: {
    outletId: number;
    registers: { id: number; name: string; active: boolean }[];
}) {
    const [editing, setEditing] = useState<{ id: number | null } | null>(null);
    const form = useForm({ name: '', active: true });

    function open(
        register: { id: number; name: string; active: boolean } | null,
    ) {
        form.clearErrors();
        form.setData({
            name: register?.name ?? '',
            active: register?.active ?? true,
        });
        setEditing({ id: register?.id ?? null });
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-4">
                <div className="flex flex-col gap-1">
                    <CardTitle>Registers</CardTitle>
                    <CardDescription>
                        Each register trades through one shift at a time.
                    </CardDescription>
                </div>
                <Button onClick={() => open(null)}>
                    <Plus data-icon="inline-start" />
                    Add register
                </Button>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="w-24">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {registers.map((register) => (
                            <TableRow key={register.id}>
                                <TableCell className="font-medium">
                                    {register.name}
                                </TableCell>
                                <TableCell>
                                    <Badge variant="secondary">
                                        {register.active
                                            ? 'Active'
                                            : 'Inactive'}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => open(register)}
                                    >
                                        Edit
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
            <FormOverlay
                variant="dialog"
                open={editing !== null}
                onOpenChange={(value) => !value && setEditing(null)}
                title={editing?.id ? 'Edit register' : 'Add register'}
                errors={form.errors}
                processing={form.processing}
                submitLabel="Save register"
                onSubmit={() => {
                    const options = {
                        preserveScroll: true,
                        onSuccess: () => setEditing(null),
                    };

                    if (editing?.id) {
                        form.put(
                            updateRegister.url({
                                outlet: outletId,
                                register: editing.id,
                            }),
                            options,
                        );
                    } else {
                        form.post(
                            storeRegister.url({ outlet: outletId }),
                            options,
                        );
                    }
                }}
            >
                <Field data-invalid={!!form.errors.name}>
                    <FieldLabel htmlFor="register-name">Name</FieldLabel>
                    <Input
                        id="register-name"
                        maxLength={80}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        autoFocus
                    />
                </Field>
                <Field orientation="horizontal">
                    <Switch
                        id="register-active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                    />
                    <FieldLabel htmlFor="register-active">Active</FieldLabel>
                </Field>
            </FormOverlay>
        </Card>
    );
}

PosNativeOutlets.layout = {
    breadcrumbs: posSettingsBreadcrumbs('registers'),
};
