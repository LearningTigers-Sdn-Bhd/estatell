import { Form, router, useForm } from '@inertiajs/react';
import { CreditCard, ImageOff, Plus, Store, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
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
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { update as updateOutlet } from '@/routes/settings/pos/outlets';
import {
    destroy as destroyCfdQr,
    store as storeCfdQr,
} from '@/routes/settings/pos/outlets/cfd-qr';
import {
    store as storePaymentMethod,
    update as updatePaymentMethod,
} from '@/routes/settings/pos/payment-methods';
import {
    store as storeRegister,
    update as updateRegister,
} from '@/routes/settings/pos/registers';

export type OutletSettingsRecord = {
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
    allowAnonymousQr: boolean;
    allowQrCustomerRemark: boolean;
    cfdWelcomeMessage: string | null;
    cfdDuitnowQrUrl: string | null;
};

export type OutletPaymentMethodRow = {
    id: number;
    paymentMethodId: number;
    paymentMethodName: string;
    tenderType: string;
    tenderLabel: string;
    pulseDrawer: boolean;
    active: boolean;
    sortOrder: number;
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
    [
        'allow_anonymous_qr',
        'Allow guests to order from table QR without login',
        'allowAnonymousQr',
    ],
    [
        'allow_qr_customer_remark',
        'Allow guest kitchen remarks on QR orders',
        'allowQrCustomerRemark',
    ],
] as const;

export function OutletSettingsForm({
    outlet,
    codes,
    operationModes,
    roundingModes,
    roundingPolicies,
}: {
    outlet: OutletSettingsRecord;
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
        allow_anonymous_qr: outlet.allowAnonymousQr,
        allow_qr_customer_remark: outlet.allowQrCustomerRemark,
        cfd_welcome_message: outlet.cfdWelcomeMessage ?? '',
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
                            cfd_welcome_message:
                                data.cfd_welcome_message || null,
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
                                    aria-invalid={!!form.errors[name]}
                                />
                                <FieldError>{form.errors[name]}</FieldError>
                            </Field>
                        ))}
                        <Field data-invalid={!!form.errors.operation_mode}>
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
                            <FieldError>
                                {form.errors.operation_mode}
                            </FieldError>
                        </Field>
                        <Field data-invalid={!!form.errors.rounding_mode}>
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
                            <FieldError>{form.errors.rounding_mode}</FieldError>
                        </Field>
                        <Field data-invalid={!!form.errors.rounding_policy}>
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
                            <FieldError>
                                {form.errors.rounding_policy}
                            </FieldError>
                        </Field>
                        <Field
                            data-invalid={!!form.errors.sst_registration_number}
                        >
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
                                aria-invalid={
                                    !!form.errors.sst_registration_number
                                }
                            />
                            <FieldError>
                                {form.errors.sst_registration_number}
                            </FieldError>
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
                                aria-invalid={!!form.errors.room_charge_limit}
                            />
                            <FieldDescription>
                                A bill above this needs a manager. Leave empty
                                for no limit.
                            </FieldDescription>
                            <FieldError>
                                {form.errors.room_charge_limit}
                            </FieldError>
                        </Field>
                    </div>
                    <Field data-invalid={!!form.errors.cfd_welcome_message}>
                        <FieldLabel htmlFor="cfd-welcome">
                            Customer display welcome message
                        </FieldLabel>
                        <Input
                            id="cfd-welcome"
                            maxLength={160}
                            value={form.data.cfd_welcome_message}
                            onChange={(event) =>
                                form.setData(
                                    'cfd_welcome_message',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.cfd_welcome_message}
                        />
                        <FieldDescription>
                            Shown on the guest-facing screen when no order is
                            open.
                        </FieldDescription>
                        <FieldError>
                            {form.errors.cfd_welcome_message}
                        </FieldError>
                    </Field>
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
                    <CfdQrField
                        outletId={outlet.id}
                        qrUrl={outlet.cfdDuitnowQrUrl}
                    />
                    <div>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Save outlet settings
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

/**
 * The outlet's static DuitNow QR, shown on the customer display at checkout. It uploads as soon as a file is picked and is
 * not part of the settings Save.
 */
function CfdQrField({
    outletId,
    qrUrl,
}: {
    outletId: number;
    qrUrl: string | null;
}) {
    const fileInput = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string>();
    const [removeOpen, setRemoveOpen] = useState(false);

    const upload = (file: File) => {
        setError(undefined);
        router.post(
            storeCfdQr.url({ outlet: outletId }),
            { qr: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setUploading(true),
                onError: (errors) => setError(errors.qr),
                onFinish: () => {
                    setUploading(false);

                    if (fileInput.current) {
                        fileInput.current.value = '';
                    }
                },
            },
        );
    };

    return (
        <Field>
            <FieldLabel htmlFor="cfd-qr">
                Customer display DuitNow QR
            </FieldLabel>
            <div className="flex flex-wrap items-center gap-4">
                <div className="flex h-28 w-28 items-center justify-center rounded-md border bg-muted/40 p-2">
                    {qrUrl ? (
                        <img
                            src={qrUrl}
                            alt="Static DuitNow QR"
                            className="max-h-full max-w-full object-contain"
                        />
                    ) : (
                        <ImageOff className="size-5 text-muted-foreground" />
                    )}
                </div>
                <input
                    ref={fileInput}
                    id="cfd-qr"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    className="hidden"
                    onChange={(event) => {
                        const file = event.target.files?.[0];

                        if (file) {
                            upload(file);
                        }
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    disabled={uploading}
                    focusableWhenDisabled
                    onClick={() => fileInput.current?.click()}
                >
                    {uploading ? (
                        <Spinner data-icon="inline-start" />
                    ) : (
                        <Upload data-icon="inline-start" />
                    )}
                    {qrUrl ? 'Replace QR' : 'Upload QR'}
                </Button>
                {qrUrl && (
                    <AlertDialog open={removeOpen} onOpenChange={setRemoveOpen}>
                        <AlertDialogTrigger
                            render={<Button type="button" variant="ghost" />}
                        >
                            Remove
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Remove the DuitNow QR?
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    The customer display stops showing a QR at
                                    checkout. You can upload one again later.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <Form
                                {...destroyCfdQr.form({
                                    outlet: outletId,
                                })}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setRemoveOpen(false)}
                            >
                                {({ processing }) => (
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>
                                            Cancel
                                        </AlertDialogCancel>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && (
                                                <Spinner data-icon="inline-start" />
                                            )}
                                            Remove QR
                                        </Button>
                                    </AlertDialogFooter>
                                )}
                            </Form>
                        </AlertDialogContent>
                    </AlertDialog>
                )}
            </div>
            <FieldDescription>
                PNG, JPEG or WebP, up to 1 MB. Shown beside the total at
                checkout so guests can scan it from the counter screen.
            </FieldDescription>
            <FieldError>{error}</FieldError>
        </Field>
    );
}

export function OutletPaymentMethodsCard({
    outletId,
    rows,
    paymentMethods,
    tenderTypes,
}: {
    outletId: number;
    rows: OutletPaymentMethodRow[];
    paymentMethods: EnumOption[];
    tenderTypes: EnumOption[];
}) {
    const [editing, setEditing] = useState<{
        row: OutletPaymentMethodRow | null;
    } | null>(null);
    const form = useForm({
        payment_method_id: '',
        tender_type: 'cash',
        pulse_drawer: false,
        active: true,
        sort_order: '0',
    });

    function open(row: OutletPaymentMethodRow | null) {
        form.clearErrors();
        form.setData({
            payment_method_id: row ? String(row.paymentMethodId) : '',
            tender_type: row?.tenderType ?? 'cash',
            pulse_drawer: row?.pulseDrawer ?? row === null,
            active: row?.active ?? true,
            sort_order: String(row?.sortOrder ?? rows.length),
        });
        setEditing({ row });
    }

    const addButton = (
        <Button onClick={() => open(null)}>
            <Plus data-icon="inline-start" />
            Add payment method
        </Button>
    );

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-4">
                <div className="flex flex-col gap-1">
                    <CardTitle>Payment methods</CardTitle>
                    <CardDescription>
                        Map property payment methods to POS tenders (Cash, Card,
                        E-wallet, DuitNow QR, Voucher). At least one active Cash
                        method is required for shifts and the daily journal.
                    </CardDescription>
                </div>
                {rows.length > 0 && addButton}
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <EmptyState
                        icon={CreditCard}
                        title="No payment methods yet"
                        description="Add a Cash payment method and any card or wallet methods accepted at this outlet."
                        action={addButton}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Payment method</TableHead>
                                <TableHead>POS tender</TableHead>
                                <TableHead>Drawer</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-24">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell className="font-medium">
                                        {row.paymentMethodName}
                                    </TableCell>
                                    <TableCell>{row.tenderLabel}</TableCell>
                                    <TableCell>
                                        {row.pulseDrawer
                                            ? 'Opens drawer'
                                            : 'No'}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant="secondary"
                                            className={
                                                row.active
                                                    ? 'bg-success/10 text-success'
                                                    : 'bg-muted text-muted-foreground'
                                            }
                                        >
                                            {row.active ? 'Active' : 'Inactive'}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => open(row)}
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
            <FormOverlay
                variant="sheet"
                open={editing !== null}
                onOpenChange={(value) => !value && setEditing(null)}
                title={
                    editing?.row ? 'Edit payment method' : 'Add payment method'
                }
                description="Choose how this tender clears to your property payment method in the daily journal."
                errors={form.errors}
                processing={form.processing}
                submitLabel="Save payment method"
                onSubmit={() => {
                    const options = {
                        preserveScroll: true,
                        onSuccess: () => setEditing(null),
                    };

                    if (editing?.row) {
                        form.put(
                            updatePaymentMethod.url({
                                outlet: outletId,
                                method: editing.row.id,
                            }),
                            options,
                        );
                    } else {
                        form.post(
                            storePaymentMethod.url({ outlet: outletId }),
                            options,
                        );
                    }
                }}
            >
                <Field data-invalid={!!form.errors.payment_method_id}>
                    <FieldLabel htmlFor="pm-method">Payment method</FieldLabel>
                    <EnumSelect
                        id="pm-method"
                        name="payment_method_id"
                        options={paymentMethods}
                        value={form.data.payment_method_id}
                        onValueChange={(value) =>
                            form.setData('payment_method_id', value)
                        }
                        placeholder="Pick a property payment method"
                        aria-invalid={!!form.errors.payment_method_id}
                    />
                    <FieldError>{form.errors.payment_method_id}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.tender_type}>
                    <FieldLabel htmlFor="pm-tender">POS tender type</FieldLabel>
                    <EnumSelect
                        id="pm-tender"
                        name="tender_type"
                        options={tenderTypes}
                        value={form.data.tender_type}
                        onValueChange={(value) =>
                            form.setData((data) => ({
                                ...data,
                                tender_type: value,
                                pulse_drawer:
                                    value === 'cash' ? true : data.pulse_drawer,
                            }))
                        }
                        aria-invalid={!!form.errors.tender_type}
                    />
                    <FieldError>{form.errors.tender_type}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.sort_order}>
                    <FieldLabel htmlFor="pm-sort">Display order</FieldLabel>
                    <Input
                        id="pm-sort"
                        type="number"
                        min={0}
                        max={999}
                        className="tabular-nums"
                        value={form.data.sort_order}
                        onChange={(event) =>
                            form.setData('sort_order', event.target.value)
                        }
                    />
                    <FieldError>{form.errors.sort_order}</FieldError>
                </Field>
                <Field orientation="horizontal">
                    <Switch
                        id="pm-drawer"
                        checked={form.data.pulse_drawer}
                        onCheckedChange={(checked) =>
                            form.setData('pulse_drawer', checked)
                        }
                    />
                    <FieldLabel htmlFor="pm-drawer">
                        Open cash drawer on payment
                    </FieldLabel>
                </Field>
                <Field orientation="horizontal">
                    <Switch
                        id="pm-active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                    />
                    <FieldLabel htmlFor="pm-active">Active</FieldLabel>
                </Field>
            </FormOverlay>
        </Card>
    );
}

export function OutletRegistersCard({
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

    const addButton = (
        <Button onClick={() => open(null)}>
            <Plus data-icon="inline-start" />
            Add register
        </Button>
    );

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-4">
                <div className="flex flex-col gap-1">
                    <CardTitle>Registers</CardTitle>
                    <CardDescription>
                        Each register trades through one shift at a time.
                    </CardDescription>
                </div>
                {registers.length > 0 && addButton}
            </CardHeader>
            <CardContent>
                {registers.length === 0 ? (
                    <EmptyState
                        icon={Store}
                        title="No registers yet"
                        description="Add a register so cashiers can open shifts and take orders."
                        action={addButton}
                    />
                ) : (
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
                                        <Badge
                                            variant="secondary"
                                            className={
                                                register.active
                                                    ? 'bg-success/10 text-success'
                                                    : 'bg-muted text-muted-foreground'
                                            }
                                        >
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
                )}
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
                        aria-invalid={!!form.errors.name}
                        autoFocus
                    />
                    <FieldError>{form.errors.name}</FieldError>
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
