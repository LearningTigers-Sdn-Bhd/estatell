import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { listQueue } from '@/lib/pos-offline-store';
import { show as offlineShow } from '@/routes/pos/offline';
import { beginClose, cancelClose, close, count } from '@/routes/pos/shifts';
import { store as storeMovement } from '@/routes/pos/shifts/movements';
import { PosApprovalFields, noApproval } from './pos-approval-fields';
import type { PosStaff } from './pos-types';

/** Cash in or out of the drawer. Safe drop / float in pre-select the outlet safe clearing account. */
export function PosCashMovementDialog({
    open,
    onClose,
    shiftId,
    staff,
    codes,
    kinds,
    safeClearingCodeId,
    canVoid,
}: {
    open: boolean;
    onClose: () => void;
    shiftId: number;
    staff: PosStaff[];
    codes: EnumOption[];
    kinds: EnumOption[];
    safeClearingCodeId: number | null;
    canVoid: boolean;
}) {
    const defaultSafeCode =
        safeClearingCodeId !== null ? String(safeClearingCodeId) : '';
    const form = useForm({
        kind: 'safe_drop',
        amount: '',
        reason: '',
        counter_transaction_code_id: defaultSafeCode,
        idempotency_key: '',
        ...noApproval,
    });

    function onKindChange(nextKind: string) {
        form.setData((data) => ({
            ...data,
            kind: nextKind,
            counter_transaction_code_id:
                nextKind === 'safe_drop' || nextKind === 'float_in'
                    ? defaultSafeCode || data.counter_transaction_code_id
                    : nextKind === 'payout'
                      ? data.counter_transaction_code_id === defaultSafeCode
                          ? ''
                          : data.counter_transaction_code_id
                      : '',
        }));
    }

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
                        data.kind === 'no_sale_open'
                            ? null
                            : data.counter_transaction_code_id || null,
                }));
                form.post(storeMovement.url({ shift: shiftId }), {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        form.setData(
                            'counter_transaction_code_id',
                            defaultSafeCode,
                        );
                        onClose();
                    },
                });
            }}
        >
            <Field data-invalid={!!form.errors.kind}>
                <FieldLabel htmlFor="movement-kind">Kind</FieldLabel>
                <EnumSelect
                    id="movement-kind"
                    name="kind"
                    options={kinds}
                    value={form.data.kind}
                    onValueChange={onKindChange}
                />
                <FieldError>{form.errors.kind}</FieldError>
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
                            aria-invalid={!!form.errors.amount}
                        />
                        <FieldError>{form.errors.amount}</FieldError>
                    </Field>
                    <Field
                        data-invalid={!!form.errors.counter_transaction_code_id}
                    >
                        <FieldLabel htmlFor="movement-code">
                            {form.data.kind === 'payout'
                                ? 'Paid out for'
                                : 'Counter account'}
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
                            aria-invalid={
                                !!form.errors.counter_transaction_code_id
                            }
                        />
                        <FieldError>
                            {form.errors.counter_transaction_code_id}
                        </FieldError>
                    </Field>
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
                    aria-invalid={!!form.errors.reason}
                />
                <FieldError>{form.errors.reason}</FieldError>
            </Field>
            {form.data.kind === 'no_sale_open' && (
                <PosApprovalFields
                    required={!canVoid}
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
 * the Z-report is frozen. Blocks closing while unsynced offline sales remain on this device.
 */
export function PosCloseShiftDialog({
    open,
    onClose,
    shift,
    outletId,
    registerId,
    staff,
}: {
    open: boolean;
    onClose: () => void;
    shift: App.Data.Pos.PosShiftData;
    outletId: number;
    registerId: number;
    staff: PosStaff[];
}) {
    const form = useForm({ counted_cash: '', ...noApproval });
    const note = useForm({ note: '' });
    const [counted, setCounted] = useState(false);
    const [unsyncedCount, setUnsyncedCount] = useState(0);
    const closing = shift.status === 'closing_control';

    useEffect(() => {
        if (!open) {
            return;
        }

        void listQueue()
            .then((items) => setUnsyncedCount(items.length))
            .catch(() => setUnsyncedCount(0));
    }, [open]);

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
                unsyncedCount > 0 ||
                (closing && !counted && form.data.counted_cash === '')
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
                if (unsyncedCount > 0) {
                    return;
                }

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
            {unsyncedCount > 0 && (
                <div
                    role="alert"
                    className="flex flex-col gap-2 rounded-lg border border-warning bg-warning/10 p-3 text-sm"
                >
                    <p className="font-medium">
                        {unsyncedCount} offline sale(s) on this device have not
                        been synced yet.
                    </p>
                    <p className="text-muted-foreground">
                        Sync all offline sales before closing the shift so cash
                        totals and Z-reports match.
                    </p>
                    <div>
                        <Link
                            href={offlineShow.url(undefined, {
                                query: { outlet: outletId, register: registerId },
                            })}
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}
                        >
                            Open offline sync
                        </Link>
                    </div>
                </div>
            )}
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
                            aria-invalid={!!form.errors.counted_cash}
                            autoFocus
                        />
                        <FieldError>{form.errors.counted_cash}</FieldError>
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
