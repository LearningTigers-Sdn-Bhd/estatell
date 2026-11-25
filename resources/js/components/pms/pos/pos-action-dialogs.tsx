import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { discount, voidMethod as voidOrder } from '@/routes/pos/orders';
import { voidMethod as voidLine } from '@/routes/pos/orders/lines';
import { PosApprovalFields, noApproval } from './pos-approval-fields';
import type { ApprovalData } from './pos-approval-fields';
import type { PosStaff, PosVoidReason } from './pos-types';

type Props = {
    open: boolean;
    onClose: () => void;
    staff: PosStaff[];
};

/** Void a sent line or a whole open order: a reason is required, a manager approves a sent line. */
export function PosVoidDialog({
    open,
    onClose,
    staff,
    orderId,
    line,
    reasons,
}: Props & {
    orderId: number;
    line: App.Data.Pos.PosOrderLineData | null;
    reasons: PosVoidReason[];
}) {
    const form = useForm({
        void_reason_id: '',
        reason_text: '',
        return_stock: false,
        ...noApproval,
    });
    const target = line === null ? 'this order' : line.itemName;

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

const DISCOUNT_TYPES = [
    { value: 'fixed_percent', label: 'Percent off' },
    { value: 'fixed_amount', label: 'Amount off' },
    { value: 'foc', label: 'Free of charge' },
];

/** Discount the whole order, or take it off again. */
export function PosDiscountDialog({
    open,
    onClose,
    staff,
    orderId,
}: Props & { orderId: number }) {
    const form = useForm({
        type: 'fixed_percent',
        value: '',
        reason: '',
        remove: false,
        ...noApproval,
    });
    const [approval, setApproval] = useState<ApprovalData>(noApproval);

    function send(remove: boolean) {
        router.post(
            discount.url({ order: orderId }),
            { ...form.data, remove, ...approval },
            { preserveScroll: true, onSuccess: () => onClose() },
        );
    }

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(value) => !value && onClose()}
            title="Discount"
            errors={form.errors}
            processing={form.processing}
            submitLabel="Apply discount"
            footerStart={
                <Button type="button" variant="link" onClick={() => send(true)}>
                    Remove discount
                </Button>
            }
            onSubmit={() => send(false)}
        >
            <Field>
                <FieldLabel htmlFor="discount-type">Type</FieldLabel>
                <EnumSelect
                    id="discount-type"
                    name="type"
                    options={DISCOUNT_TYPES}
                    value={form.data.type}
                    onValueChange={(value) => form.setData('type', value)}
                />
            </Field>
            {form.data.type !== 'foc' && (
                <Field>
                    <FieldLabel htmlFor="discount-value">Value</FieldLabel>
                    <Input
                        id="discount-value"
                        inputMode="decimal"
                        className="tabular-nums"
                        value={form.data.value}
                        onChange={(event) =>
                            form.setData('value', event.target.value)
                        }
                    />
                </Field>
            )}
            <Field>
                <FieldLabel htmlFor="discount-reason">Reason</FieldLabel>
                <Input
                    id="discount-reason"
                    maxLength={160}
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                />
            </Field>
            <PosApprovalFields
                staff={staff}
                data={approval}
                onChange={setApproval}
            />
        </FormOverlay>
    );
}
