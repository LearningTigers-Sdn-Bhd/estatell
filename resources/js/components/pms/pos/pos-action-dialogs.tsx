import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    discount,
    transferRegister,
    voidMethod as voidOrder,
} from '@/routes/pos/orders';
import { voidMethod as voidLine } from '@/routes/pos/orders/lines';
import { PosApprovalFields, noApproval } from './pos-approval-fields';
import type { PosStaff, PosVoidReason } from './pos-types';

type Props = {
    open: boolean;
    onClose: () => void;
    staff: PosStaff[];
};

/** Void a sent line or a whole open order: a reason is required, a manager approves when the cashier lacks `pos.void`. */
export function PosVoidDialog({
    open,
    onClose,
    staff,
    orderId,
    line,
    reasons,
    requiresApproval,
}: Props & {
    orderId: number;
    line: App.Data.Pos.PosOrderLineData | null;
    reasons: PosVoidReason[];
    requiresApproval: boolean;
}) {
    const form = useForm({
        void_reason_id: '',
        reason_text: '',
        return_stock: false,
        ...noApproval,
    });
    const target = line === null ? 'this order' : line.itemName;
    const pickedReason = reasons.find(
        (reason) => String(reason.id) === form.data.void_reason_id,
    );

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(value) => !value && onClose()}
            title={`Void ${target}?`}
            description="The reason is kept on the audit trail."
            errors={form.errors}
            processing={form.processing}
            submitLabel="Void"
            submitVariant="destructive"
            onSubmit={() =>
                form.post(
                    line === null
                        ? voidOrder.url({ order: orderId })
                        : voidLine.url({ order: orderId, line: line.id }),
                    {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset();
                            onClose();
                        },
                    },
                )
            }
        >
            <Field data-invalid={!!form.errors.void_reason_id}>
                <FieldLabel htmlFor="void-reason">Reason</FieldLabel>
                <EnumSelect
                    id="void-reason"
                    name="void_reason_id"
                    options={reasons.map((reason) => ({
                        value: String(reason.id),
                        label: reason.label,
                    }))}
                    value={form.data.void_reason_id}
                    onValueChange={(value) =>
                        form.setData('void_reason_id', value)
                    }
                    placeholder="Pick a reason"
                />
                <FieldError>{form.errors.void_reason_id}</FieldError>
            </Field>
            <Field>
                <FieldLabel htmlFor="void-note">Note</FieldLabel>
                <Input
                    id="void-note"
                    maxLength={255}
                    value={form.data.reason_text}
                    onChange={(event) =>
                        form.setData('reason_text', event.target.value)
                    }
                />
            </Field>
            <label className="flex items-center gap-3">
                <Checkbox
                    checked={form.data.return_stock}
                    onCheckedChange={(value) =>
                        form.setData('return_stock', value === true)
                    }
                />
                <span>Put the stock back</span>
            </label>
            <PosApprovalFields
                required={
                    requiresApproval ||
                    (pickedReason?.requires_manager_pin ?? false)
                }
                staff={staff}
                data={{
                    approver_id: form.data.approver_id,
                    approver_pin: form.data.approver_pin,
                }}
                onChange={(next) =>
                    form.setData((data) => ({ ...data, ...next }))
                }
            />
        </FormOverlay>
    );
}

/** Discount the whole order or one line, or remove an existing discount. */
export function PosDiscountDialog({
    open,
    onClose,
    staff,
    orderId,
    lines,
    discountTypes,
    requiresApproval,
}: Props & {
    orderId: number;
    lines: App.Data.Pos.PosOrderLineData[];
    discountTypes: EnumOption[];
    requiresApproval: boolean;
}) {
    const standingLines = lines.filter(
        (line) =>
            !line.isVoided && !line.isPackagingFee && !line.isEntitlementCovered,
    );
    const form = useForm({
        line_id: '',
        type: discountTypes[0]?.value ?? 'fixed_percent',
        value: '',
        reason: '',
        exclude_addons: false,
        remove: false,
        ...noApproval,
    });

    function submit(remove: boolean) {
        form.transform((data) => ({
            ...data,
            line_id: data.line_id === '' ? null : Number(data.line_id),
            remove,
        }));
        form.post(discount.url({ order: orderId }), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    }

    const lineOptions: EnumOption[] = standingLines.map((line) => ({
        value: String(line.id),
        label: `${line.quantity} × ${line.itemName}`,
    }));

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={(value) => !value && onClose()}
            title="Discount"
            description="Apply to the whole bill or pick a single item."
            errors={form.errors}
            processing={form.processing}
            submitLabel="Apply discount"
            footerStart={
                <Button
                    type="button"
                    variant="link"
                    disabled={form.processing}
                    onClick={() => submit(true)}
                >
                    Remove discount
                </Button>
            }
            onSubmit={() => submit(false)}
        >
            <Field>
                <FieldLabel htmlFor="discount-scope">Apply to</FieldLabel>
                <EnumSelect
                    id="discount-scope"
                    name="line_id"
                    allLabel="Whole bill"
                    options={lineOptions}
                    value={form.data.line_id}
                    onValueChange={(value) => form.setData('line_id', value)}
                />
            </Field>
            <Field data-invalid={!!form.errors.type}>
                <FieldLabel htmlFor="discount-type">Type</FieldLabel>
                <EnumSelect
                    id="discount-type"
                    name="type"
                    options={discountTypes}
                    value={form.data.type}
                    onValueChange={(value) => form.setData('type', value)}
                />
                <FieldError>{form.errors.type}</FieldError>
            </Field>
            {form.data.type !== 'foc' && (
                <Field data-invalid={!!form.errors.value}>
                    <FieldLabel htmlFor="discount-value">Value</FieldLabel>
                    <Input
                        id="discount-value"
                        inputMode="decimal"
                        className="tabular-nums"
                        value={form.data.value}
                        onChange={(event) =>
                            form.setData('value', event.target.value)
                        }
                        aria-invalid={!!form.errors.value}
                    />
                    <FieldError>{form.errors.value}</FieldError>
                </Field>
            )}
            {form.data.line_id !== '' && (
                <label className="flex items-center gap-3">
                    <Checkbox
                        checked={form.data.exclude_addons}
                        onCheckedChange={(value) =>
                            form.setData('exclude_addons', value === true)
                        }
                    />
                    <span>Exclude add-ons / modifiers from discount</span>
                </label>
            )}
            <Field data-invalid={!!form.errors.reason}>
                <FieldLabel htmlFor="discount-reason">Reason</FieldLabel>
                <Input
                    id="discount-reason"
                    maxLength={160}
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    aria-invalid={!!form.errors.reason}
                />
                <FieldError>{form.errors.reason}</FieldError>
            </Field>
            <PosApprovalFields
                required={requiresApproval}
                staff={staff}
                data={{
                    approver_id: form.data.approver_id,
                    approver_pin: form.data.approver_pin,
                }}
                onChange={(next) =>
                    form.setData((data) => ({ ...data, ...next }))
                }
            />
        </FormOverlay>
    );
}

/** Manager PIN override prompt for pre-bill reprint or post-prebill dining option change. */
export function PosManagerOverrideDialog({
    open,
    onClose,
    title,
    description,
    url,
    payload,
    staff,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description: string;
    url: string;
    payload: Record<string, unknown>;
    staff: PosStaff[];
}) {
    const form = useForm({ ...noApproval });

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(value) => !value && onClose()}
            title={title}
            description={description}
            errors={form.errors}
            processing={form.processing}
            submitLabel="Approve & continue"
            onSubmit={() => {
                form.transform((data) => ({ ...payload, ...data }));
                form.post(url, {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        onClose();
                    },
                });
            }}
        >
            <PosApprovalFields
                required
                staff={staff}
                data={{
                    approver_id: form.data.approver_id,
                    approver_pin: form.data.approver_pin,
                }}
                onChange={(next) =>
                    form.setData((data) => ({ ...data, ...next }))
                }
            />
        </FormOverlay>
    );
}

/** Move an open order to another active register on the same outlet (e.g. before closing a shift). */
export function PosTransferRegisterDialog({
    open,
    onClose,
    orderId,
    currentRegisterId,
    registers,
}: {
    open: boolean;
    onClose: () => void;
    orderId: number;
    currentRegisterId: number;
    registers: { id: number; name: string }[];
}) {
    const targets = registers
        .filter((reg) => reg.id !== currentRegisterId)
        .map((reg) => ({ value: String(reg.id), label: reg.name }));
    const form = useForm({ register_id: targets[0]?.value ?? '' });

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(value) => !value && onClose()}
            title="Transfer order to register"
            description="Move this open check to another register with an open shift so you can close this register."
            errors={form.errors}
            processing={form.processing}
            submitLabel="Transfer order"
            submitDisabled={form.data.register_id === ''}
            onSubmit={() => {
                form.transform((data) => ({
                    register_id: Number(data.register_id),
                }));
                form.post(transferRegister.url({ order: orderId }), {
                    preserveScroll: true,
                    onSuccess: () => onClose(),
                });
            }}
        >
            <Field data-invalid={!!form.errors.register_id}>
                <FieldLabel htmlFor="transfer-register">
                    Target register
                </FieldLabel>
                <EnumSelect
                    id="transfer-register"
                    name="register_id"
                    options={targets}
                    value={form.data.register_id}
                    onValueChange={(value) =>
                        form.setData('register_id', value)
                    }
                    placeholder="Pick a register"
                />
                <FieldError>{form.errors.register_id}</FieldError>
            </Field>
        </FormOverlay>
    );
}
