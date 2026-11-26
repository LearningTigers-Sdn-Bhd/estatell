import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { PosApprovalFields, noApproval } from './pos-approval-fields';
import type { PosStaff } from './pos-types';
import { unlessRefused } from './pos-unless-refused';

type RefundLine = {
    order_line_id: number;
    quantity: number;
    return_stock: boolean;
    restore_meal_entitlement: boolean;
};

/**
 * Refund by line and quantity. At the register a manager approves with their PIN (`staff`); from the back office the
 * signed-in user holding the refund permission is the approver, so `staff` is left out.
 */
export function PosRefundDialog({
    open,
    onClose,
    order,
    url,
    staff,
    requiresApproval = true,
}: {
    open: boolean;
    onClose: () => void;
    order: App.Data.Pos.PosOrderData;
    url: string;
    staff?: PosStaff[];
    /** False when the signed-in user may refund alone; the PIN fields are then optional. */
    requiresApproval?: boolean;
}) {
    const refundable = order.lines.filter(
        (line) =>
            !line.isVoided &&
            !line.isPackagingFee &&
            Number(line.refundableQuantity) > 0,
    );
    const form = useForm<{
        lines: RefundLine[];
        reason: string;
        approver_id: string;
        approver_pin: string;
    }>({ lines: [], reason: '', ...noApproval });

    function toggle(id: number, max: number, on: boolean) {
        form.setData(
            'lines',
            on
                ? [
                      ...form.data.lines,
                      {
                          order_line_id: id,
                          quantity: max,
                          return_stock: false,
                          restore_meal_entitlement: false,
                      },
                  ]
                : form.data.lines.filter((row) => row.order_line_id !== id),
        );
    }

    function patch(id: number, change: Partial<RefundLine>) {
        form.setData(
            'lines',
            form.data.lines.map((row) =>
                row.order_line_id === id ? { ...row, ...change } : row,
            ),
        );
    }

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={(value) => !value && onClose()}
            title={`Refund ${order.orderNumber ?? 'order'}`}
            description="Pick what comes back and how many. Each line goes back at the price it was sold for."
            errors={form.errors}
            processing={form.processing}
            submitDisabled={form.data.lines.length === 0}
            submitLabel="Refund"
            submitVariant="destructive"
            onSubmit={() =>
                form.post(url, {
                    preserveScroll: true,
                    onSuccess: unlessRefused(() => {
                        form.reset();
                        onClose();
                    }),
                })
            }
        >
            <div className="flex flex-col gap-3">
                {refundable.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Everything on this order was already refunded.
                    </p>
                )}
                {refundable.map((line) => {
                    const picked = form.data.lines.find(
                        (row) => row.order_line_id === line.id,
                    );
                    const max = Math.floor(Number(line.refundableQuantity));

                    return (
                        <div
                            key={line.id}
                            className="flex flex-col gap-2 rounded-lg border p-3"
                        >
                            <label className="flex items-center gap-3">
                                <Checkbox
                                    checked={picked !== undefined}
                                    onCheckedChange={(value) =>
                                        toggle(line.id, max, value === true)
                                    }
                                />
                                <span className="flex-1">{line.itemName}</span>
                                <span className="text-sm text-muted-foreground tabular-nums">
                                    up to {max}
                                </span>
                            </label>
                            {picked && (
                                <div className="flex flex-wrap items-center gap-4 pl-7">
                                    <Input
                                        type="number"
                                        min={1}
                                        max={max}
                                        aria-label={`Quantity of ${line.itemName} to refund`}
                                        className="w-20 tabular-nums"
                                        value={picked.quantity}
                                        onChange={(event) =>
                                            patch(line.id, {
                                                quantity: Math.min(
                                                    max,
                                                    Math.max(
                                                        1,
                                                        Number(
                                                            event.target.value,
                                                        ) || 1,
                                                    ),
                                                ),
                                            })
                                        }
                                    />
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={picked.return_stock}
                                            onCheckedChange={(value) =>
                                                patch(line.id, {
                                                    return_stock:
                                                        value === true,
                                                })
                                            }
                                        />
                                        Put the stock back
                                    </label>
                                    {line.isEntitlementCovered && (
                                        <label className="flex items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={
                                                    picked.restore_meal_entitlement
                                                }
                                                onCheckedChange={(value) =>
                                                    patch(line.id, {
                                                        restore_meal_entitlement:
                                                            value === true,
                                                    })
                                                }
                                            />
                                            Give the included meal back
                                        </label>
                                    )}
                                </div>
                            )}
                        </div>
                    );
                })}
                <Field data-invalid={!!form.errors.reason}>
                    <FieldLabel htmlFor="refund-reason">Reason</FieldLabel>
                    <Input
                        id="refund-reason"
                        maxLength={255}
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                    />
                </Field>
                {staff && (
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
                )}
            </div>
        </FormOverlay>
    );
}
