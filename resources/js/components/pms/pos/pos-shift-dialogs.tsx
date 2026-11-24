import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Button } from '@/components/ui/button';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { beginClose, cancelClose, close, count } from '@/routes/pos/shifts';
import { store as storeMovement } from '@/routes/pos/shifts/movements';
import { PosApprovalFields, noApproval } from './pos-approval-fields';
import type { PosStaff } from './pos-types';

type Option = { value: string; label: string };

const KINDS: Option[] = [
    { value: 'float_in', label: 'Float in' },
    { value: 'safe_drop', label: 'Safe drop' },
    { value: 'payout', label: 'Payout' },
    { value: 'no_sale_open', label: 'No sale (open drawer)' },
];

/** Cash in or out of the drawer. A no-sale drawer open needs a manager. */
export function PosCashMovementDialog({
    open,
    onClose,
    shiftId,
    staff,
    codes,
}: {
    open: boolean;
    onClose: () => void;
    shiftId: number;
    staff: PosStaff[];
    codes: Option[];
}) {
    const form = useForm({
        kind: 'safe_drop',
        amount: '',
        reason: '',
        counter_transaction_code_id: '',
        idempotency_key: '',
        ...noApproval,
    });

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(value) => !value && onClose()}
            title="Cash movement"
            errors={form.errors}
            processing={form.processing}
            submitLabel="Save movement"
            onSubmit={() => {
                form.transform((data) => ({
                    ...data,
                    idempotency_key: crypto.randomUUID(),
                    counter_transaction_code_id:
                        data.counter_transaction_code_id || null,
                }));
                form.post(storeMovement.url({ shift: shiftId }), {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        onClose();
                    },
                });
            }}
        >
            <Field>
                <FieldLabel htmlFor="movement-kind">Kind</FieldLabel>
                <EnumSelect
                    id="movement-kind"
                    name="kind"
                    options={KINDS}
                    value={form.data.kind}
                    onValueChange={(value) => form.setData('kind', value)}
                />
            </Field>
            {form.data.kind !== 'no_sale_open' && (
                <>
                    <Field data-invalid={!!form.errors.amount}>
                        <FieldLabel htmlFor="movement-amount">
                            Amount
                        </FieldLabel>
                        <Input
                            id="movement-amount"
                            inputMode="decimal"
                            className="tabular-nums"
                            value={form.data.amount}
                            onChange={(event) =>
                                form.setData('amount', event.target.value)
                            }
                        />
                    </Field>
                    {form.data.kind === 'payout' && (
                        <Field>
                            <FieldLabel htmlFor="movement-code">
                                Paid out for
                            </FieldLabel>
                            <EnumSelect
                                id="movement-code"
                                name="counter_transaction_code_id"
                                options={codes}
                                value={form.data.counter_transaction_code_id}
                                onValueChange={(value) =>
                                    form.setData(
                                        'counter_transaction_code_id',
                                        value,
                                    )
                                }
                                placeholder="Pick an account"
                            />
                        </Field>
                    )}
                </>
            )}
            <Field data-invalid={!!form.errors.reason}>
                <FieldLabel htmlFor="movement-reason">Reason</FieldLabel>
                <Input
                    id="movement-reason"
                    maxLength={160}
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                />
            </Field>
            {form.data.kind === 'no_sale_open' && (
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
            )}
        </FormOverlay>
    );
}

/**
 * Close the shift blind: the cashier counts the drawer without seeing what it should hold, then the shift closes and
 * the Z-report is frozen. A recount needs a manager.
 */
export function PosCloseShiftDialog({
    open,
    onClose,
    shift,
    staff,
}: {
    open: boolean;
    onClose: () => void;
    shift: App.Data.Pos.PosShiftData;
    staff: PosStaff[];
}) {
    const form = useForm({ counted_cash: '', ...noApproval });
    const note = useForm({ note: '' });
    const [counted, setCounted] = useState(false);
    const closing = shift.status === 'closing_control';

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={(value) => !value && onClose()}
            title="Close the shift"
            description={
                closing
                    ? 'Count the cash in the drawer. The expected amount stays hidden until the shift is closed.'
                    : 'Trading stops on this register while you count.'
            }
            errors={{ ...form.errors, ...note.errors }}
            processing={form.processing || note.processing}
            submitLabel={
                !closing
                    ? 'Start closing'
                    : counted
                      ? 'Close shift'
                      : 'Save count'
            }
            submitDisabled={
                closing && !counted && form.data.counted_cash === ''
            }
            footerStart={
                closing ? (
                    <Button
                        type="button"
                        variant="link"
                        onClick={() =>
                            router.post(
                                cancelClose.url({ shift: shift.id }),
                                {},
                                {
                                    preserveScroll: true,
                                    onSuccess: () => onClose(),
                                },
                            )
                        }
                    >
                        Resume trading
                    </Button>
                ) : undefined
            }
            onSubmit={() => {
                if (!closing) {
                    router.post(
                        beginClose.url({ shift: shift.id }),
                        {},
                        { preserveScroll: true },
                    );

                    return;
                }

                if (!counted) {
                    form.post(count.url({ shift: shift.id }), {
                        preserveScroll: true,
                        onSuccess: () => setCounted(true),
                    });

                    return;
                }

                note.post(close.url({ shift: shift.id }));
            }}
        >
            {closing && !counted && (
                <>
                    <Field data-invalid={!!form.errors.counted_cash}>
                        <FieldLabel htmlFor="counted-cash">
                            Cash counted
                        </FieldLabel>
                        <Input
                            id="counted-cash"
                            inputMode="decimal"
                            className="tabular-nums"
                            value={form.data.counted_cash}
                            onChange={(event) =>
                                form.setData('counted_cash', event.target.value)
                            }
                            autoFocus
                        />
                    </Field>
                    {shift.recountCount > 0 && (
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
                    )}
                </>
            )}
            {closing && counted && (
                <Field>
                    <FieldLabel htmlFor="close-note">Closing note</FieldLabel>
                    <Input
                        id="close-note"
                        maxLength={255}
                        value={note.data.note}
                        onChange={(event) =>
                            note.setData('note', event.target.value)
                        }
                    />
                    <FieldDescription>
                        The count is saved. Close the shift to freeze the
                        Z-report, or count again.
                    </FieldDescription>
                    <Button
                        type="button"
                        variant="link"
                        onClick={() => setCounted(false)}
                    >
                        Count again
                    </Button>
                </Field>
            )}
        </FormOverlay>
    );
}
