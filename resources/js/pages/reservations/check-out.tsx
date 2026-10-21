import { Head, Link, useForm } from '@inertiajs/react';
import { LogOut, X } from 'lucide-react';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormActions } from '@/components/pms/form-actions';
import { FormError } from '@/components/pms/form-error';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import {
    AuditPendingNote,
    AuditPendingStrip,
} from '@/components/pms/reservations/audit-pending';
import { CheckOutDialog } from '@/components/pms/reservations/check-out-dialog';
import { GroupCheckOutDone } from '@/components/pms/reservations/group-check-out-done';
import { GroupCheckOutMutedList } from '@/components/pms/reservations/group-check-out-muted';
import {
    GroupCheckOutTable,
    initialRowState,
    type RowState,
} from '@/components/pms/reservations/group-check-out-row';
import {
    useGroupCheckOutPreview,
    type GroupCheckOutPayload,
} from '@/components/pms/reservations/use-group-check-out-preview';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { checkOut, index, show } from '@/routes/reservations';
import { store } from '@/routes/reservations/check-out';

type Props = {
    checkOut: App.Data.Reservations.GroupCheckOutPageData;
    canWaiveFees: boolean;
    canPrintReceipts: boolean;
    paymentOptions?: App.Data.Payments.PaymentFormOptionsData;
};

type Row = App.Data.Reservations.GroupCheckOutRowData;

const plural = (count: number, one: string, many: string) =>
    `${count} ${count === 1 ? one : many}`;

/**
 * Group check-out: every room of a reservation is checked out and its money settled on one page. The browser keeps
 * only the ticks and typed fields; the server works out every figure in a preview 300 ms after a change and checks
 * them again on save.
 */
export default function CheckOut({
    checkOut: page,
    canWaiveFees,
    canPrintReceipts,
    paymentOptions,
}: Props) {
    const currency = page.currency;
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const [refreshToken, setRefreshToken] = useState(0);
    const [done, setDone] =
        useState<App.Data.Reservations.GroupCheckOutDoneData | null>(null);
    const [rowStates, setRowStates] = useState<Record<number, RowState>>(() =>
        Object.fromEntries(
            page.preview.rows.map((row) => [row.unitId, initialRowState(row)]),
        ),
    );
    const [onePaymentOn, setOnePaymentOn] = useState(false);
    const [payerId, setPayerId] = useState('');
    const [payerMethod, setPayerMethod] = useState('');
    const [payerReference, setPayerReference] = useState('');
    const [settleMaster, setSettleMaster] = useState(false);
    const [early, setEarly] = useState<{
        unit: App.Data.Reservations.ReservationUnitData;
        open: boolean;
    } | null>(null);

    // Rows come back from the server after a refusal or a reload; a new room gets its prefilled state.
    const rows = page.preview.rows;
    const states = useMemo(
        () =>
            Object.fromEntries(
                rows.map((row) => [
                    row.unitId,
                    rowStates[row.unitId] ?? initialRowState(row),
                ]),
            ),
        [rows, rowStates],
    );
    const methods = page.preview.methods;
    const methodOf = (id: string) =>
        methods.find((one) => String(one.id) === id);

    const payers = rows.filter((row) => {
        const state = states[row.unitId];

        return (
            state.ticked &&
            row.selectable &&
            !row.folioClosed &&
            row.folioId !== null &&
            (row.group === 'ready' || row.group === 'money') &&
            (state.choice === 'collect' || state.choice === null)
        );
    });
    const payer =
        payers.find((row) => String(row.unitId) === payerId) ?? payers[0];
    const payingMethodId = payerMethod || String(methods[0]?.id ?? '');
    const onePaymentActive = onePaymentOn && payer !== undefined;
    const master = page.preview.master;
    const settleActive = settleMaster && master?.canSettle === true;

    const chosen = rows.filter(
        (row) => states[row.unitId].ticked && row.selectable,
    );
    const buildRows = (): GroupCheckOutPayload['rows'] =>
        chosen.map((row) => {
            const state = states[row.unitId];
            const base = { unit_id: row.unitId };

            if (state.choice === 'collect') {
                return {
                    ...base,
                    choice: 'collect',
                    payment_method_id: Number(state.methodId) || undefined,
                    reference: methodOf(state.methodId)?.requiresReference
                        ? state.reference
                        : undefined,
                    amount: state.amount,
                };
            }

            if (state.choice === 'refund') {
                return {
                    ...base,
                    choice: 'refund',
                    amount: state.refundAmount,
                };
            }

            return state.choice === 'move' ? { ...base, choice: 'move' } : base;
        });
    const onePayment = (amount: string): GroupCheckOutPayload['one_payment'] =>
        onePaymentActive && payingMethodId !== ''
            ? {
                  payer_unit_id: payer.unitId,
                  payment_method_id: Number(payingMethodId),
                  reference: methodOf(payingMethodId)?.requiresReference
                      ? payerReference
                      : undefined,
                  amount,
              }
            : undefined;

    // The preview ignores the amount of One payment for all: it works the total out itself.
    const previewPayload: GroupCheckOutPayload = {
        rows: buildRows(),
        one_payment: onePayment('0.00'),
        settle_master: settleActive,
    };
    const { preview, current } = useGroupCheckOutPreview(
        page.reservationId,
        page.preview,
        previewPayload,
        refreshToken,
    );
    const summary = preview.summary;
    const onePaymentAmount = summary.onePayment ?? '0.00';

    // Check out early… saves on its own and the page comes back with new rows: ask the server for the totals again. The
    // ticks and typed fields stay in `rowStates`.
    const firstRows = useRef(rows);
    useEffect(() => {
        if (firstRows.current !== rows) {
            firstRows.current = rows;
            setRefreshToken((token) => token + 1);
        }
    }, [rows]);

    const form = useForm({});
    const errors = form.errors as Record<string, string | undefined>;

    // The total of One payment for all changed on the server: ask again so the page shows the new amount.
    useEffect(() => {
        if (errors.form && onePaymentActive) {
            setRefreshToken((token) => token + 1);
        }
        // Ask again only when a new refusal arrives.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errors.form]);

    const outCount = chosen.filter((row) => row.status === 'checked_in').length;
    const settleCount = chosen.filter(
        (row) => row.status !== 'checked_in' && states[row.unitId].choice,
    ).length;
    const label =
        outCount > 0
            ? `Check out ${plural(outCount, 'room', 'rooms')}${settleCount > 0 ? ` and settle ${settleCount}` : ''}`
            : settleCount > 0
              ? `Settle ${settleCount}`
              : 'Check out';
    const collecting = chosen.some(
        (row) =>
            states[row.unitId].choice === 'collect' &&
            (onePaymentActive || states[row.unitId].methodId !== ''),
    );
    const canSave =
        chosen.length > 0 &&
        current &&
        preview.error === null &&
        !form.processing &&
        (!onePaymentOn || (onePaymentActive && Number(onePaymentAmount) > 0)) &&
        (collecting ||
            !chosen.some((row) => states[row.unitId].choice === 'collect'));

    const tickAll = (unitIds: number[], ticked: boolean) =>
        setRowStates((current) => ({
            ...current,
            ...Object.fromEntries(
                unitIds.map((unitId) => [
                    unitId,
                    { ...states[unitId], ticked },
                ]),
            ),
        }));
    const changeRow = (unitId: number, patch: Partial<RowState>) =>
        setRowStates((current) => ({
            ...current,
            [unitId]: { ...states[unitId], ...patch },
        }));

    const submit = () => {
        if (!canSave) {
            return;
        }

        form.transform(() => ({
            idempotency_key: idempotencyKey,
            rows: buildRows(),
            one_payment: onePayment(onePaymentAmount),
            settle_master: settleActive,
        }));
        form.post(store.url({ reservation: page.reservationId }), {
            preserveScroll: true,
            onSuccess: (response) => {
                const flashed = (
                    response.flash as
                        | {
                              groupCheckOut?: App.Data.Reservations.GroupCheckOutDoneData;
                          }
                        | undefined
                )?.groupCheckOut;

                if (flashed) {
                    setDone(flashed);
                }
            },
        });
    };

    const otherErrors = Object.entries(errors).filter(
        ([key]) => key !== 'form' && !key.startsWith('units.'),
    );
    const cancelUrl = page.cancelUrl;
    const submitButton = (
        <Button type="submit" disabled={!canSave} focusableWhenDisabled>
            {form.processing ? (
                <Spinner data-icon="inline-start" />
            ) : (
                <LogOut data-icon="inline-start" />
            )}
            {label}
        </Button>
    );
    const positive = (amount: string) => Number(amount) > 0;
    const hasMoney =
        positive(summary.collect) ||
        positive(summary.refund) ||
        positive(summary.moveToMaster);
    const parts: ReactNode[] = [];

    if (summary.roomsOut > 0) {
        parts.push(
            `${plural(summary.roomsOut, 'room', 'rooms')} ${summary.roomsOut === 1 ? 'checks' : 'check'} out`,
        );
    }

    if (summary.roomsSettled > 0) {
        parts.push(`${summary.roomsSettled} settled`);
    }

    if (positive(summary.collect)) {
        parts.push(
            <>
                Collect{' '}
                <MoneyText amount={summary.collect} currency={currency} plain />
            </>,
        );
    }

    if (positive(summary.refund)) {
        parts.push(
            <>
                Refund{' '}
                <MoneyText amount={summary.refund} currency={currency} plain />
            </>,
        );
    }

    if (positive(summary.moveToMaster)) {
        parts.push(
            <>
                Add{' '}
                <MoneyText
                    amount={summary.moveToMaster}
                    currency={currency}
                    plain
                />{' '}
                to group bill
            </>,
        );
    }

    const summaryLine = (
        <p className="text-sm text-muted-foreground">
            {parts.length === 0 && 'No rooms selected.'}
            {parts.map((part, position) => (
                <Fragment key={position}>
                    {position > 0 && ' · '}
                    {part}
                </Fragment>
            ))}
            {parts.length > 0 && !hasMoney && '. Nothing to settle.'}
        </p>
    );
    const inGroup = (...groups: string[]) =>
        rows.filter((row) => groups.includes(row.group));
    const tableProps = {
        states,
        onChange: changeRow,
        onTickAll: tickAll,
        methods,
        currency,
        reservationId: page.reservationId,
        onePaymentOn: onePaymentActive,
        errors,
    };
    const checkOutEarly = (row: Row) =>
        row.unit !== null && setEarly({ unit: row.unit, open: true });
    const nothing = (names: string[], what: string) =>
        names.length === 0
            ? null
            : `${plural(names.length, 'room', 'rooms')} ${what} with nothing to settle (${names.join(', ')}).`;
    const showMaster =
        master !== null &&
        (Number(master.balance) !== 0 ||
            positive(summary.moveToMaster) ||
            master.canSettle);

    if (done !== null) {
        return (
            <>
                <Head title={page.title} />
                <div
                    scroll-region=""
                    className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 pb-12"
                >
                    <PageHeader
                        title="Check-out saved"
                        description={page.title}
                        back={{ href: cancelUrl, label: 'reservation' }}
                    />
                    <GroupCheckOutDone
                        done={done}
                        currency={currency}
                        reservationId={page.reservationId}
                        reservationUrl={cancelUrl}
                        canPrintReceipts={canPrintReceipts}
                    />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={page.title} />

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
                className="flex min-h-0 flex-1 flex-col"
            >
                <div
                    scroll-region=""
                    className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 pb-12"
                >
                    <PageHeader
                        title={page.title}
                        back={{ href: cancelUrl, label: 'reservation' }}
                        actions={
                            <div className="hidden items-center gap-2 lg:flex">
                                <Link
                                    href={cancelUrl}
                                    className={buttonVariants({
                                        variant: 'outline',
                                    })}
                                >
                                    <X data-icon="inline-start" />
                                    Cancel
                                </Link>
                                {submitButton}
                            </div>
                        }
                    />

                    <FormError errors={errors} />
                    <AuditPendingStrip pending={preview.auditPending} />
                    <AuditPendingNote pending={preview.auditPending} />
                    {preview.error !== null && (
                        <Alert variant="destructive">
                            <AlertDescription>{preview.error}</AlertDescription>
                        </Alert>
                    )}
                    {otherErrors.length > 0 && (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {otherErrors.map(([key, message]) => (
                                    <span key={key} className="block">
                                        {message}
                                    </span>
                                ))}
                            </AlertDescription>
                        </Alert>
                    )}

                    {inGroup('ready').length > 0 && (
                        <GroupCheckOutTable
                            heading="Ready to check out"
                            rows={inGroup('ready')}
                            {...tableProps}
                        />
                    )}
                    {inGroup('money').length > 0 && (
                        <GroupCheckOutTable
                            heading="Money to settle"
                            rows={inGroup('money')}
                            {...tableProps}
                        />
                    )}
                    {inGroup('later').length > 0 && (
                        <GroupCheckOutMutedList
                            heading="Departing later"
                            rows={inGroup('later')}
                            reservationId={page.reservationId}
                            onCheckOutEarly={checkOutEarly}
                        />
                    )}
                    {inGroup('blocked').length > 0 && (
                        <GroupCheckOutMutedList
                            heading="Can't check out yet"
                            rows={inGroup('blocked')}
                            reservationId={page.reservationId}
                            onCheckOutEarly={checkOutEarly}
                        />
                    )}
                    {[
                        nothing(
                            preview.checkedOutNothing,
                            'already checked out',
                        ),
                        nothing(preview.cancelledNothing, 'cancelled'),
                    ].map(
                        (line) =>
                            line !== null && (
                                <p
                                    key={line}
                                    className="text-sm text-muted-foreground"
                                >
                                    {line}
                                </p>
                            ),
                    )}

                    {payers.length > 0 && methods.length > 0 && (
                        <div className="flex flex-col gap-3 rounded-lg border p-3">
                            <Field orientation="horizontal">
                                <Checkbox
                                    id="one-payment"
                                    checked={onePaymentOn}
                                    onCheckedChange={(checked) =>
                                        setOnePaymentOn(checked === true)
                                    }
                                />
                                <FieldLabel htmlFor="one-payment">
                                    One payment for all
                                    {onePaymentActive && (
                                        <>
                                            {' · collect '}
                                            <MoneyText
                                                amount={onePaymentAmount}
                                                currency={currency}
                                            />
                                            {' in one go'}
                                        </>
                                    )}
                                </FieldLabel>
                            </Field>
                            {onePaymentOn && (
                                <div className="flex flex-wrap gap-2">
                                    <div className="w-64">
                                        <EnumSelect
                                            options={payers.map((row) => ({
                                                value: String(row.unitId),
                                                label: `${row.label} · ${row.guest ?? 'No primary guest'}`,
                                            }))}
                                            value={String(payer?.unitId ?? '')}
                                            onValueChange={setPayerId}
                                            aria-label="Room that pays"
                                        />
                                    </div>
                                    <div className="w-40">
                                        <EnumSelect
                                            options={methods.map((one) => ({
                                                value: String(one.id),
                                                label: one.name,
                                            }))}
                                            value={payingMethodId}
                                            onValueChange={setPayerMethod}
                                            aria-label="Method of the one payment"
                                        />
                                    </div>
                                    {methodOf(payingMethodId)
                                        ?.requiresReference && (
                                        <Input
                                            className="w-40"
                                            value={payerReference}
                                            onChange={(event) =>
                                                setPayerReference(
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={100}
                                            autoComplete="off"
                                            placeholder="Reference"
                                            aria-label="Reference of the one payment"
                                        />
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {master !== null && showMaster && (
                        <div className="flex flex-col gap-3 rounded-lg border p-3">
                            <div className="flex flex-col gap-1">
                                <p className="text-sm font-medium">
                                    Group bill (Master folio) · {master.name}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Charges the group pays, not the guests.
                                </p>
                                <p className="text-sm">
                                    Balance now{' '}
                                    <MoneyText
                                        amount={master.balance}
                                        currency={currency}
                                        plain
                                    />
                                    {' · Added from rooms +'}
                                    <MoneyText
                                        amount={summary.moveToMaster}
                                        currency={currency}
                                        plain
                                    />
                                    {' · After check-out '}
                                    <MoneyText
                                        amount={
                                            summary.masterAfter ??
                                            master.balance
                                        }
                                        currency={currency}
                                        plain
                                    />
                                </p>
                            </div>
                            {master.canSettle && (
                                <Field orientation="horizontal">
                                    <Checkbox
                                        id="settle-master"
                                        checked={settleMaster}
                                        onCheckedChange={(checked) =>
                                            setSettleMaster(checked === true)
                                        }
                                    />
                                    <FieldLabel htmlFor="settle-master">
                                        Send to City ledger after check-out
                                    </FieldLabel>
                                </Field>
                            )}
                        </div>
                    )}

                    <div className="hidden lg:block">{summaryLine}</div>
                </div>

                <div className="lg:hidden">
                    <FormActions fixed>
                        <div className="w-full">{summaryLine}</div>
                        <Link
                            href={cancelUrl}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            <X data-icon="inline-start" />
                            Cancel
                        </Link>
                        {submitButton}
                    </FormActions>
                </div>
            </form>

            {early !== null && (
                <CheckOutDialog
                    open={early.open}
                    onOpenChange={(open) =>
                        setEarly((current) =>
                            current === null ? null : { ...current, open },
                        )
                    }
                    reservationId={page.reservationId}
                    unit={early.unit}
                    paymentOptions={paymentOptions}
                    canWaiveFees={canWaiveFees}
                    currency={currency}
                />
            )}
        </>
    );
}

CheckOut.layout = (props: {
    checkOut: App.Data.Reservations.GroupCheckOutPageData;
    property: App.Data.Property.PropertySummaryData;
}) => ({
    variant: 'fixed' as const,
    breadcrumbs: [
        { title: 'Reservations', href: index(props.property.code) },
        {
            title: props.checkOut.reference,
            href: show({
                property: props.property.code,
                reservation: props.checkOut.reservationId,
            }),
        },
        {
            title: 'Check out',
            href: checkOut({
                property: props.property.code,
                reservation: props.checkOut.reservationId,
            }),
        },
    ],
});
