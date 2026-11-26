import { Form, router, useForm } from '@inertiajs/react';
import {
    Banknote,
    Check,
    CreditCard,
    ImageOff,
    Monitor,
    Plus,
    QrCode,
    Receipt,
    ShieldCheck,
    Store,
    Ticket,
    TriangleAlert,
    Upload,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { StatusBadge } from '@/components/pms/status-badge';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
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
import { unlessRefused } from './pos-unless-refused';

export type OutletSettingsSection = 'controls' | 'accounting' | 'display';

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

function tenderIcon(tenderType: string): LucideIcon {
    const normalized = tenderType.toLocaleLowerCase();

    if (normalized.includes('cash')) {
        return Banknote;
    }

    if (normalized.includes('qr') || normalized.includes('duitnow')) {
        return QrCode;
    }

    if (normalized.includes('wallet')) {
        return Wallet;
    }

    if (normalized.includes('voucher')) {
        return Ticket;
    }

    return CreditCard;
}

const CODE_FIELDS = [
    [
        'service_charge_transaction_code_id',
        'Service charge account',
        'Posts dining service charge on guest folios and the daily journal.',
        true,
    ],
    [
        'packaging_transaction_code_id',
        'Packaging fee account',
        'Posts takeaway or delivery packaging fees.',
        true,
    ],
    [
        'rounding_transaction_code_id',
        'Cash rounding account',
        'Captures small 5-sen cash rounding differences.',
        false,
    ],
    [
        'cash_variance_transaction_code_id',
        'Cash short or over account',
        'Captures shift closing cash discrepancies.',
        false,
    ],
    [
        'safe_clearing_transaction_code_id',
        'Safe clearing account',
        'Tracks cash drops moved from the till drawer to the safe.',
        false,
    ],
] as const;

type SwitchFieldName =
    | 'require_room_charge_signature'
    | 'require_meal_redemption_confirmation'
    | 'require_reprint_pin'
    | 'require_dual_control'
    | 'print_receipt_after_payment'
    | 'print_drawer_slip'
    | 'require_clock_in'
    | 'allow_anonymous_qr'
    | 'allow_qr_customer_remark';

const APPROVAL_SWITCHES: readonly [SwitchFieldName, string, string][] = [
    [
        'require_room_charge_signature',
        'Guest signs a room charge slip',
        'Print a signature line whenever a bill is posted to a hotel room folio.',
    ],
    [
        'require_meal_redemption_confirmation',
        'Guest confirms an included meal',
        'Require host confirmation when redeeming an included breakfast, lunch, or dinner.',
    ],
    [
        'require_reprint_pin',
        'Manager approves a pre-bill reprint',
        'Ask for a manager PIN before printing a duplicate pre-bill check.',
    ],
    [
        'require_dual_control',
        'Dual control approval',
        'Require a different staff member to approve sensitive manager actions.',
    ],
    [
        'require_clock_in',
        'Staff must clock in to take orders',
        'Block order entry on Terminal Mode until the staff member clocks in.',
    ],
];

const RECEIPT_SWITCHES: readonly [SwitchFieldName, string, string][] = [
    [
        'print_receipt_after_payment',
        'Offer receipt after payment',
        'Prompt cashiers to print or share the receipt as soon as a check settles.',
    ],
    [
        'print_drawer_slip',
        'Print a drawer slip',
        'Print a slip whenever a cashier records a paid-in, paid-out, or safe drop.',
    ],
];

const QR_SWITCHES: readonly [SwitchFieldName, string, string][] = [
    [
        'allow_anonymous_qr',
        'Allow table QR ordering without login',
        'Let seated guests scan a table QR code and place orders without signing in.',
    ],
    [
        'allow_qr_customer_remark',
        'Allow guest kitchen remarks on QR orders',
        'Show a notes box on table QR items for allergies or preparation requests.',
    ],
];

/** Editorial settings row: section title and context on the left, framed controls on the right. */
function SettingsBand({
    icon: Icon,
    title,
    description,
    children,
}: {
    icon?: LucideIcon;
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="grid grid-cols-1 gap-4 lg:grid-cols-[17rem_minmax(0,1fr)] lg:gap-8">
            <div className="flex flex-col gap-1 lg:pt-1">
                <div className="flex items-center gap-2">
                    {Icon && (
                        <Icon
                            aria-hidden="true"
                            className="size-4 shrink-0 text-muted-foreground"
                        />
                    )}
                    <h2 className="font-heading text-sm font-semibold text-foreground">
                        {title}
                    </h2>
                </div>
                <p className="text-xs leading-relaxed text-muted-foreground">
                    {description}
                </p>
            </div>
            <div className="min-w-0">{children}</div>
        </section>
    );
}

function SwitchRow({
    id,
    label,
    description,
    checked,
    onCheckedChange,
}: {
    id: string;
    label: string;
    description: string;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4 px-4 py-3.5">
            <div className="flex flex-col gap-0.5">
                <FieldLabel
                    htmlFor={id}
                    className="text-sm font-medium text-foreground"
                >
                    {label}
                </FieldLabel>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>
            <Switch
                id={id}
                checked={checked}
                onCheckedChange={onCheckedChange}
            />
        </div>
    );
}

export function OutletSettingsForm({
    outlet,
    activeSection = 'controls',
    codes,
    chargeCodes,
    operationModes,
    roundingModes,
    roundingPolicies,
}: {
    outlet: OutletSettingsRecord;
    /** When null, the form stays mounted (preserving unsaved edits) while hidden. */
    activeSection?: OutletSettingsSection | null;
    codes: EnumOption[];
    /** Room charges post the service charge and packaging codes on a guest folio, so only charge codes can be picked for them. */
    chargeCodes: EnumOption[];
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

    const isVisible = activeSection !== null;
    const previewGreeting =
        form.data.cfd_welcome_message.trim() || `Welcome to ${outlet.name}`;

    return (
        <form
            className={cn('flex flex-col gap-6', !isVisible && 'hidden')}
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
                    cfd_welcome_message: data.cfd_welcome_message || null,
                }));
                form.put(updateOutlet.url({ outlet: outlet.id }), {
                    preserveScroll: true,
                });
            }}
        >
            {/* Till controls & QR: editorial left-context / right-controls bands */}
            <div
                className={cn(
                    'flex flex-col gap-8',
                    activeSection !== 'controls' && 'hidden',
                )}
            >
                <SettingsBand
                    title="Service style & rounding"
                    description={`Controls checkout flow, 5-sen cash rounding, and room-charge approval thresholds for ${outlet.name}.`}
                >
                    <div className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2">
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
                        <Field data-invalid={!!form.errors.room_charge_limit}>
                            <FieldLabel htmlFor="limit">
                                Room charge limit
                            </FieldLabel>
                            <Input
                                id="limit"
                                inputMode="decimal"
                                className="tabular-nums"
                                placeholder="No limit"
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
                                Bills above this amount require manager approval
                                before posting to a room.
                            </FieldDescription>
                            <FieldError>
                                {form.errors.room_charge_limit}
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
                                Rounding applies to
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
                    </div>
                </SettingsBand>

                <div className="border-t" />

                <SettingsBand
                    icon={ShieldCheck}
                    title="Approvals & staff security"
                    description="Protect room postings, included-meal redemptions, pre-bill reprints, and staff clock-in rules."
                >
                    <div className="divide-y overflow-hidden rounded-xl border bg-card">
                        {APPROVAL_SWITCHES.map(([name, label, description]) => (
                            <SwitchRow
                                key={name}
                                id={name}
                                label={label}
                                description={description}
                                checked={form.data[name]}
                                onCheckedChange={(checked) =>
                                    form.setData(name, checked)
                                }
                            />
                        ))}
                    </div>
                </SettingsBand>

                <div className="border-t" />

                <SettingsBand
                    icon={Receipt}
                    title="Receipts & cash drawer"
                    description="Control automatic receipt prompts and audit slips for cash drawer movements."
                >
                    <div className="divide-y overflow-hidden rounded-xl border bg-card">
                        {RECEIPT_SWITCHES.map(([name, label, description]) => (
                            <SwitchRow
                                key={name}
                                id={name}
                                label={label}
                                description={description}
                                checked={form.data[name]}
                                onCheckedChange={(checked) =>
                                    form.setData(name, checked)
                                }
                            />
                        ))}
                    </div>
                </SettingsBand>

                <div className="border-t" />

                <SettingsBand
                    icon={QrCode}
                    title="Table QR ordering"
                    description="Configure how seated guests interact with table QR menus and kitchen instructions."
                >
                    <div className="divide-y overflow-hidden rounded-xl border bg-card">
                        {QR_SWITCHES.map(([name, label, description]) => (
                            <SwitchRow
                                key={name}
                                id={name}
                                label={label}
                                description={description}
                                checked={form.data[name]}
                                onCheckedChange={(checked) =>
                                    form.setData(name, checked)
                                }
                            />
                        ))}
                    </div>
                </SettingsBand>
            </div>

            {/* Accounting & tax: editorial left-context / right-controls bands */}
            <div
                className={cn(
                    'flex flex-col gap-8',
                    activeSection !== 'accounting' && 'hidden',
                )}
            >
                <SettingsBand
                    title="Daily journal accounts"
                    description={`Maps ${outlet.name} charges, rounding, cash discrepancies, and safe drops to property transaction codes.`}
                >
                    <div className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2">
                        {CODE_FIELDS.map(
                            ([name, label, helper, chargeOnly]) => (
                                <Field
                                    key={name}
                                    data-invalid={!!form.errors[name]}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <FieldLabel htmlFor={name}>
                                            {label}
                                        </FieldLabel>
                                        {chargeOnly && (
                                            <Badge variant="outline">
                                                Charge code
                                            </Badge>
                                        )}
                                    </div>
                                    <EnumSelect
                                        id={name}
                                        name={name}
                                        options={
                                            chargeOnly ? chargeCodes : codes
                                        }
                                        value={form.data[name]}
                                        onValueChange={(value) =>
                                            form.setData(name, value)
                                        }
                                        placeholder="Pick an account"
                                        aria-invalid={!!form.errors[name]}
                                    />
                                    <FieldDescription>
                                        {helper}
                                    </FieldDescription>
                                    <FieldError>{form.errors[name]}</FieldError>
                                </Field>
                            ),
                        )}
                    </div>
                </SettingsBand>

                <div className="border-t" />

                <SettingsBand
                    title="Tax registration"
                    description="Printed on customer tax receipts and e-invoice summaries issued by this outlet."
                >
                    <div className="rounded-xl border bg-card p-5">
                        <Field
                            data-invalid={!!form.errors.sst_registration_number}
                            className="max-w-md"
                        >
                            <FieldLabel htmlFor="sst">
                                SST registration number
                            </FieldLabel>
                            <Input
                                id="sst"
                                placeholder="e.g. W10-1808-32000123"
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
                    </div>
                </SettingsBand>
            </div>

            {/* Customer display: split controls (left) + realistic counter tablet mockup (right) */}
            <div
                className={cn(
                    'grid grid-cols-1 items-start gap-6 lg:grid-cols-2',
                    activeSection !== 'display' && 'hidden',
                )}
            >
                <div className="flex flex-col gap-5">
                    <section className="flex flex-col gap-4 rounded-xl border bg-card p-5">
                        <div className="flex flex-col gap-0.5">
                            <h2 className="font-heading text-base font-semibold text-foreground">
                                Welcome message
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Displayed on the guest-facing screen when no
                                order is active at the counter.
                            </p>
                        </div>
                        <Field data-invalid={!!form.errors.cfd_welcome_message}>
                            <FieldLabel htmlFor="cfd-welcome">
                                Idle greeting
                            </FieldLabel>
                            <Input
                                id="cfd-welcome"
                                maxLength={160}
                                placeholder={`Welcome to ${outlet.name}`}
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
                                Up to 160 characters. Leave blank to show the
                                outlet name.
                            </FieldDescription>
                            <FieldError>
                                {form.errors.cfd_welcome_message}
                            </FieldError>
                        </Field>
                    </section>

                    <CfdQrField
                        outletId={outlet.id}
                        qrUrl={outlet.cfdDuitnowQrUrl}
                    />
                </div>

                <section
                    aria-label="Counter screen live preview"
                    className="overflow-hidden rounded-xl border bg-card"
                >
                    <header className="flex items-center justify-between border-b bg-muted/40 px-4 py-3">
                        <span className="inline-flex items-center gap-2 font-heading text-sm font-semibold text-foreground">
                            <Monitor
                                aria-hidden="true"
                                className="size-4 text-muted-foreground"
                            />
                            Live counter display preview
                        </span>
                        <Badge variant="outline">Guest view</Badge>
                    </header>
                    <div className="p-6">
                        <div className="flex flex-col items-center gap-5 rounded-2xl border-2 border-border bg-muted/30 p-6 text-center">
                            <span className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                {outlet.name}
                            </span>
                            <p className="max-w-sm font-heading text-xl font-semibold tracking-tight text-foreground">
                                {previewGreeting}
                            </p>
                            <div className="flex size-36 items-center justify-center rounded-xl border bg-card p-3 shadow-xs">
                                {outlet.cfdDuitnowQrUrl ? (
                                    <img
                                        src={outlet.cfdDuitnowQrUrl}
                                        alt="Static DuitNow QR preview"
                                        className="max-h-full max-w-full object-contain"
                                    />
                                ) : (
                                    <div className="flex flex-col items-center gap-1.5 text-muted-foreground">
                                        <QrCode
                                            aria-hidden="true"
                                            className="size-8 opacity-60"
                                        />
                                        <span className="text-xs">
                                            No QR uploaded
                                        </span>
                                    </div>
                                )}
                            </div>
                            <span className="text-xs text-muted-foreground">
                                {outlet.cfdDuitnowQrUrl
                                    ? 'Guests scan this DuitNow QR beside their check total.'
                                    : 'Upload a DuitNow QR on the left to show it at checkout.'}
                            </span>
                        </div>
                    </div>
                </section>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3">
                <span className="text-xs text-muted-foreground">
                    {form.isDirty
                        ? 'You have unsaved outlet setting changes.'
                        : `All settings for ${outlet.name} are saved.`}
                </span>
                <Button
                    type="submit"
                    className="ms-auto max-sm:min-h-11"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing ? (
                        <Spinner data-icon="inline-start" />
                    ) : (
                        <Check data-icon="inline-start" />
                    )}
                    Save outlet settings
                </Button>
            </div>
        </form>
    );
}

/**
 * The outlet's static DuitNow QR, shown on the customer display at checkout.
 * Uploads immediately on file pick and is independent of the main Save button.
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
        <section className="flex flex-col gap-4 rounded-xl border bg-card p-5">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-3.5">
                    <div className="flex size-16 shrink-0 items-center justify-center rounded-xl border bg-muted/40 p-2">
                        {qrUrl ? (
                            <img
                                src={qrUrl}
                                alt="Static DuitNow QR"
                                className="max-h-full max-w-full object-contain"
                            />
                        ) : (
                            <ImageOff
                                aria-hidden="true"
                                className="size-5 text-muted-foreground"
                            />
                        )}
                    </div>
                    <div className="flex flex-col gap-0.5">
                        <FieldLabel
                            htmlFor="cfd-qr"
                            className="font-heading text-sm font-semibold text-foreground"
                        >
                            Static DuitNow QR
                        </FieldLabel>
                        <p className="text-xs text-muted-foreground">
                            PNG, JPEG, or WebP up to 1 MB. Uploads immediately
                            when selected.
                        </p>
                    </div>
                </div>

                <div className="flex shrink-0 flex-wrap justify-end gap-2">
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
                    {qrUrl && (
                        <AlertDialog
                            open={removeOpen}
                            onOpenChange={setRemoveOpen}
                        >
                            <AlertDialogTrigger
                                render={
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="max-sm:min-h-11"
                                    />
                                }
                            >
                                Remove
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>
                                        Remove the DuitNow QR?
                                    </AlertDialogTitle>
                                    <AlertDialogDescription>
                                        The customer display stops showing a QR
                                        at checkout. You can upload one again
                                        later.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <Form
                                    {...destroyCfdQr.form({
                                        outlet: outletId,
                                    })}
                                    options={{ preserveScroll: true }}
                                    onSuccess={unlessRefused(() =>
                                        setRemoveOpen(false),
                                    )}
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
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="max-sm:min-h-11"
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
                </div>
            </div>
            <FieldError>{error}</FieldError>
        </section>
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

    const hasActiveCash = rows.some(
        (row) => row.active && row.tenderType === 'cash',
    );

    return (
        <section className="@container overflow-hidden rounded-xl border bg-card">
            <header className="flex flex-col gap-3 border-b bg-muted/40 px-4 py-3.5 @md:flex-row @md:items-center @md:justify-between">
                <div className="flex flex-col gap-0.5">
                    <h2 className="font-heading text-base font-semibold text-foreground">
                        Payment methods
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Map property payment methods to POS tenders (Cash, Card,
                        E-wallet, DuitNow QR, Voucher).
                    </p>
                </div>
                <div className="flex shrink-0 justify-end">
                    <Button
                        type="button"
                        size="sm"
                        className="max-sm:min-h-11"
                        onClick={() => open(null)}
                    >
                        <Plus data-icon="inline-start" />
                        Add method
                    </Button>
                </div>
            </header>

            {rows.length > 0 && !hasActiveCash && (
                <div className="flex items-center gap-2.5 border-b bg-warning/10 px-4 py-2.5 text-xs text-warning-text">
                    <TriangleAlert
                        aria-hidden="true"
                        className="size-4 shrink-0"
                    />
                    <span>
                        At least one active Cash method is required for shifts
                        and the daily journal.
                    </span>
                </div>
            )}

            {rows.length === 0 ? (
                <div className="p-8">
                    <EmptyState
                        icon={CreditCard}
                        title="No payment methods yet"
                        description="Use Add method above to add Cash plus any card or e-wallet tenders accepted here."
                    />
                </div>
            ) : (
                <div className="divide-y">
                    {rows.map((row) => {
                        const TenderIcon = tenderIcon(row.tenderType);

                        return (
                            <div
                                key={row.id}
                                className="flex items-center justify-between gap-3 px-4 py-3.5 transition-colors hover:bg-muted/25 motion-reduce:transition-none"
                            >
                                <div className="flex min-w-0 items-center gap-3">
                                    <span
                                        className={cn(
                                            'flex size-10 shrink-0 items-center justify-center rounded-xl border',
                                            row.active
                                                ? 'border-success/30 bg-success/10 text-success'
                                                : 'border-border bg-muted/50 text-muted-foreground',
                                        )}
                                    >
                                        <TenderIcon
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    </span>
                                    <div className="flex min-w-0 flex-col gap-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="truncate font-medium text-foreground">
                                                {row.paymentMethodName}
                                            </span>
                                            <StatusBadge
                                                status={{
                                                    value: row.active
                                                        ? 'active'
                                                        : 'inactive',
                                                    label: row.active
                                                        ? 'Active'
                                                        : 'Inactive',
                                                    tone: row.active
                                                        ? 'success'
                                                        : 'neutral',
                                                }}
                                            />
                                        </div>
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                                            <Badge variant="secondary">
                                                {row.tenderLabel}
                                            </Badge>
                                            <span>
                                                {row.pulseDrawer
                                                    ? 'Opens cash drawer'
                                                    : 'No drawer pulse'}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="shrink-0 max-sm:min-h-11"
                                    aria-label={`Edit ${row.paymentMethodName}`}
                                    onClick={() => open(row)}
                                >
                                    Edit
                                </Button>
                            </div>
                        );
                    })}
                </div>
            )}

            <FormOverlay
                variant="sheet"
                size="lg"
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
                        onSuccess: unlessRefused(() => setEditing(null)),
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
                <div className="flex flex-col gap-5">
                    <div className="flex flex-col gap-4 rounded-xl border bg-card p-4">
                        <Field data-invalid={!!form.errors.payment_method_id}>
                            <FieldLabel htmlFor="pm-method">
                                Payment method
                            </FieldLabel>
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
                            <FieldError>
                                {form.errors.payment_method_id}
                            </FieldError>
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field data-invalid={!!form.errors.tender_type}>
                                <FieldLabel htmlFor="pm-tender">
                                    POS tender type
                                </FieldLabel>
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
                                                value === 'cash'
                                                    ? true
                                                    : data.pulse_drawer,
                                        }))
                                    }
                                    aria-invalid={!!form.errors.tender_type}
                                />
                                <FieldError>
                                    {form.errors.tender_type}
                                </FieldError>
                            </Field>
                            <Field data-invalid={!!form.errors.sort_order}>
                                <FieldLabel htmlFor="pm-sort">
                                    Display order
                                </FieldLabel>
                                <Input
                                    id="pm-sort"
                                    type="number"
                                    min={0}
                                    max={999}
                                    className="tabular-nums"
                                    value={form.data.sort_order}
                                    onChange={(event) =>
                                        form.setData(
                                            'sort_order',
                                            event.target.value,
                                        )
                                    }
                                />
                                <FieldError>
                                    {form.errors.sort_order}
                                </FieldError>
                            </Field>
                        </div>
                    </div>

                    <div className="divide-y overflow-hidden rounded-xl border bg-card">
                        <SwitchRow
                            id="pm-drawer"
                            label="Open cash drawer on payment"
                            description="Send a kick pulse to the connected cash drawer when settling with this tender."
                            checked={form.data.pulse_drawer}
                            onCheckedChange={(checked) =>
                                form.setData('pulse_drawer', checked)
                            }
                        />
                        <SwitchRow
                            id="pm-active"
                            label="Payment method active"
                            description="Show this tender as a payment button on the POS checkout screen."
                            checked={form.data.active}
                            onCheckedChange={(checked) =>
                                form.setData('active', checked)
                            }
                        />
                    </div>
                </div>
            </FormOverlay>
        </section>
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

    return (
        <section className="@container overflow-hidden rounded-xl border bg-card">
            <header className="flex flex-col gap-3 border-b bg-muted/40 px-4 py-3.5 @md:flex-row @md:items-center @md:justify-between">
                <div className="flex flex-col gap-0.5">
                    <h2 className="font-heading text-base font-semibold text-foreground">
                        Cash registers
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Each register runs one active cashier shift and cash
                        drawer at a time.
                    </p>
                </div>
                <div className="flex shrink-0 justify-end">
                    <Button
                        type="button"
                        size="sm"
                        className="max-sm:min-h-11"
                        onClick={() => open(null)}
                    >
                        <Plus data-icon="inline-start" />
                        Add register
                    </Button>
                </div>
            </header>

            {registers.length === 0 ? (
                <div className="p-8">
                    <EmptyState
                        icon={Store}
                        title="No registers yet"
                        description="Use Add register above so cashiers can open shifts and take orders."
                    />
                </div>
            ) : (
                <div className="divide-y">
                    {registers.map((register) => (
                        <div
                            key={register.id}
                            className="flex items-center justify-between gap-3 px-4 py-3.5 transition-colors hover:bg-muted/25 motion-reduce:transition-none"
                        >
                            <div className="flex min-w-0 items-center gap-3">
                                <span
                                    className={cn(
                                        'flex size-10 shrink-0 items-center justify-center rounded-xl border',
                                        register.active
                                            ? 'border-success/30 bg-success/10 text-success'
                                            : 'border-border bg-muted/50 text-muted-foreground',
                                    )}
                                >
                                    <Store
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                </span>
                                <div className="flex min-w-0 flex-col gap-0.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="truncate font-medium text-foreground">
                                            {register.name}
                                        </span>
                                        <StatusBadge
                                            status={{
                                                value: register.active
                                                    ? 'active'
                                                    : 'inactive',
                                                label: register.active
                                                    ? 'Active'
                                                    : 'Inactive',
                                                tone: register.active
                                                    ? 'success'
                                                    : 'neutral',
                                            }}
                                        />
                                    </div>
                                    <span className="text-xs text-muted-foreground">
                                        {register.active
                                            ? 'Available for cashiers to open shifts.'
                                            : 'Paused from opening new shifts.'}
                                    </span>
                                </div>
                            </div>

                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="shrink-0 max-sm:min-h-11"
                                aria-label={`Edit ${register.name}`}
                                onClick={() => open(register)}
                            >
                                Edit
                            </Button>
                        </div>
                    ))}
                </div>
            )}

            <FormOverlay
                variant="dialog"
                open={editing !== null}
                onOpenChange={(value) => !value && setEditing(null)}
                title={editing?.id ? 'Edit register' : 'Add register'}
                description="Give the register a clear counter name so staff pick the right cash drawer when opening a shift."
                errors={form.errors}
                processing={form.processing}
                submitLabel="Save register"
                onSubmit={() => {
                    const options = {
                        preserveScroll: true,
                        onSuccess: unlessRefused(() => setEditing(null)),
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
                        placeholder="e.g. Counter 1"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!form.errors.name}
                        autoFocus
                    />
                    <FieldError>{form.errors.name}</FieldError>
                </Field>
                <div className="flex items-center justify-between gap-4 rounded-xl border bg-card p-3.5">
                    <div className="flex flex-col gap-0.5">
                        <FieldLabel
                            htmlFor="register-active"
                            className="text-sm font-medium text-foreground"
                        >
                            Register active
                        </FieldLabel>
                        <p className="text-xs text-muted-foreground">
                            Allow cashiers to open shifts on this register.
                        </p>
                    </div>
                    <Switch
                        id="register-active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                    />
                </div>
            </FormOverlay>
        </section>
    );
}
