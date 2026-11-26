import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cashRoundingAdjustment } from '@/lib/pos-rounding';
import { inHouseGuests } from '@/routes/pos';
import { settle } from '@/routes/pos/orders';
import { PosApprovalFields, noApproval } from './pos-approval-fields';
import type { ApprovalData } from './pos-approval-fields';
import type { PosCurrency, PosPaymentMethod, PosStaff } from './pos-types';

type Tender = {
    tender_type: string;
    amount: string;
    payment_method_id: string;
    tendered_amount: string;
    reservation_unit_id: string;
    guest_label: string;
    reference: string;
};

type Guest = {
    reservation_unit_id: number;
    room_number: string;
    guest_name: string;
    can_post_charges: boolean;
};

const QUICK_CASH_BILLS = [10, 20, 50, 100];

/** Per-row debounced in-house guest search with AbortController, loading/error/empty states, and selected badge. */
function RoomChargeGuestPicker({
    index,
    selectedUnitId,
    selectedLabel,
    onSelect,
}: {
    index: number;
    selectedUnitId: string;
    selectedLabel: string;
    onSelect: (unitId: string, label: string) => void;
}) {
    const [term, setTerm] = useState('');
    const [guests, setGuests] = useState<Guest[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [searched, setSearched] = useState(false);

    useEffect(() => {
        const trimmed = term.trim();

        if (trimmed.length < 2) {
            setGuests([]);
            setLoading(false);
            setError(null);
            setSearched(false);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);
            setError(null);
            fetch(inHouseGuests.url(undefined, { query: { q: trimmed } }), {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('guest_lookup_failed');
                    }

                    return response.json() as Promise<{ guests: Guest[] }>;
                })
                .then((body) => {
                    setGuests(body.guests);
                    setSearched(true);
                    setLoading(false);
                })
                .catch((err: unknown) => {
                    if (
                        err instanceof DOMException &&
                        err.name === 'AbortError'
                    ) {
                        return;
                    }

                    setError('Could not search in-house guests.');
                    setLoading(false);
                });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [term]);

    return (
        <Field>
            <FieldLabel htmlFor={`tender-room-${index}`}>
                In-house guest
            </FieldLabel>
            {selectedUnitId !== '' && selectedLabel !== '' && (
                <div className="flex items-center justify-between gap-2 rounded-md border bg-muted/40 px-3 py-2 text-sm">
                    <span className="font-medium">{selectedLabel}</span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => onSelect('', '')}
                    >
                        Change
                    </Button>
                </div>
            )}
            <Input
                id={`tender-room-${index}`}
                placeholder="Type room number or guest name (2+ chars)"
                value={term}
                onChange={(event) => setTerm(event.target.value)}
            />
            {loading && (
                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Spinner />
                    Searching...
                </p>
            )}
            {error !== null && (
                <p role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            )}
            {!loading && error === null && searched && guests.length === 0 && (
                <p className="text-xs text-muted-foreground">
                    No in-house guests found.
                </p>
            )}
            {guests.length > 0 && (
                <div className="flex flex-col gap-1">
                    {guests.map((guest) => {
                        const label = `${guest.room_number} · ${guest.guest_name}`;

                        return (
                            <Button
                                key={guest.reservation_unit_id}
                                type="button"
                                variant={
                                    selectedUnitId ===
                                    String(guest.reservation_unit_id)
                                        ? 'default'
                                        : 'outline'
                                }
                                className="min-h-11 justify-start"
                                disabled={!guest.can_post_charges}
                                onClick={() =>
                                    onSelect(
                                        String(guest.reservation_unit_id),
                                        label,
                                    )
                                }
                            >
                                {label}
                                {!guest.can_post_charges && ' (cash only)'}
                            </Button>
                        );
                    })}
                </div>
            )}
        </Field>
    );
}

/**
 * Take payment: one or more tenders with exact BNM 5-sen cash rounding preview and per-row room charge guest lookup.
 */
export function PosCheckoutSheet({
    open,
    onOpenChange,
    order,
    currency,
    methods,
    tenderTypes,
    staff,
    hasMealCover,
    roundingMode,
    roundingPolicy,
    roomChargeLimit,
    requireRoomChargeSignature,
    requireMealRedemptionConfirmation,
    otherBills = [],
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    order: App.Data.Pos.PosOrderData;
    currency: PosCurrency;
    methods: PosPaymentMethod[];
    tenderTypes: EnumOption[];
    staff: PosStaff[];
    hasMealCover: boolean;
    roundingMode: string;
    roundingPolicy: string;
    roomChargeLimit: string | null;
    requireRoomChargeSignature: boolean;
    requireMealRedemptionConfirmation: boolean;
    otherBills?: {
        id: number;
        label: string;
        openedAt: string;
        total: string;
    }[];
}) {
    const first = methods[0];
    const blank = (type: string, amount: string): Tender => ({
        tender_type: type,
        amount,
        payment_method_id: '',
        tendered_amount: '',
        reservation_unit_id:
            type === 'room_charge' && order.reservationUnitId !== null
                ? String(order.reservationUnitId)
                : '',
        guest_label:
            type === 'room_charge' && order.roomLabel !== null
                ? order.roomLabel
                : '',
        reference: '',
    });
    const [tenders, setTenders] = useState<Tender[]>([
        blank(first?.tenderType ?? 'cash', order.grandTotal),
    ]);
    const [signed, setSigned] = useState(false);
    const [meal, setMeal] = useState(false);
    const [approval, setApproval] = useState<ApprovalData>(noApproval);
    const [processing, setProcessing] = useState(false);
    const [billsChecked, setBillsChecked] = useState(false);
    const [people, setPeople] = useState('2');
    const [errors, setErrors] = useState<Record<string, string>>({});

    const tenderLabel = (value: string) =>
        tenderTypes.find((option) => option.value === value)?.label ?? value;
    const types = Array.from(
        new Set([...methods.map((method) => method.tenderType), 'room_charge']),
    );
    const total = Number(order.grandTotal);
    const paid = tenders.reduce(
        (sum, tender) => sum + (Number(tender.amount) || 0),
        0,
    );
    const remaining = Math.round((total - paid) * 100) / 100;
    const cashTotal = tenders
        .filter((tender) => tender.tender_type === 'cash')
        .reduce((sum, tender) => sum + (Number(tender.amount) || 0), 0);
    const totalRounding = cashRoundingAdjustment(
        roundingMode,
        roundingPolicy,
        total,
        cashTotal,
    );
    const firstCashIndex = tenders.findIndex(
        (tender) => tender.tender_type === 'cash',
    );
    const roomTotal = tenders
        .filter((tender) => tender.tender_type === 'room_charge')
        .reduce((sum, tender) => sum + (Number(tender.amount) || 0), 0);
    const overRoomLimit =
        roomChargeLimit !== null && roomTotal > Number(roomChargeLimit);

    function patch(index: number, change: Partial<Tender>) {
        setTenders((current) =>
            current.map((tender, at) =>
                at === index ? { ...tender, ...change } : tender,
            ),
        );
    }

    const hasRoom = tenders.some(
        (tender) => tender.tender_type === 'room_charge',
    );
    const missingRoomGuest = tenders.some(
        (tender) =>
            tender.tender_type === 'room_charge' &&
            tender.reservation_unit_id === '',
    );
    const cashUnderTendered = tenders.some((tender, index) => {
        if (tender.tender_type !== 'cash' || tender.tendered_amount === '') {
            return false;
        }

        const due =
            (Number(tender.amount) || 0) +
            (index === firstCashIndex ? totalRounding : 0);

        return Number(tender.tendered_amount) + 0.0001 < due;
    });

    function submit() {
        router.post(
            settle.url({ order: order.id }),
            {
                // A check fully covered by included meals has nothing to pay, so it sends no tender.
                tenders:
                    total === 0
                        ? []
                        : tenders.map((tender) => ({
                              tender_type: tender.tender_type,
                              amount: tender.amount,
                              payment_method_id:
                                  tender.payment_method_id || undefined,
                              tendered_amount:
                                  tender.tendered_amount || undefined,
                              reservation_unit_id:
                                  tender.reservation_unit_id || undefined,
                              reference: tender.reference || undefined,
                          })),
                guest_signed: signed,
                meal_confirmed: meal,
                ...approval,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (found) => setErrors(found),
            },
        );
    }

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={onOpenChange}
            title="Take payment"
            description={
                <span className="flex flex-wrap items-center gap-2">
                    <span>
                        Bill{' '}
                        <MoneyText
                            amount={order.grandTotal}
                            currency={currency}
                        />
                    </span>
                    {totalRounding !== 0 && (
                        <Badge variant="secondary" className="tabular-nums">
                            Rounding {totalRounding > 0 ? '+' : ''}
                            {totalRounding.toFixed(2)} · Cash payable{' '}
                            <MoneyText
                                amount={(total + totalRounding).toFixed(2)}
                                currency={currency}
                            />
                        </Badge>
                    )}
                </span>
            }
            errors={errors}
            processing={processing}
            submitLabel={total === 0 ? 'Settle check' : 'Complete payment'}
            submitDisabled={
                (otherBills.length > 0 && !billsChecked) ||
                Math.abs(remaining) > 0.005 ||
                missingRoomGuest ||
                cashUnderTendered
            }
            onSubmit={submit}
        >
            <div className="flex flex-col gap-4">
                <div className="grid grid-cols-3 gap-2 rounded-xl border bg-muted/40 p-3 text-sm">
                    <div className="flex flex-col gap-0.5">
                        <span className="text-xs text-muted-foreground">
                            Bill total
                        </span>
                        <span className="font-heading text-base font-semibold text-foreground tabular-nums">
                            <MoneyText
                                amount={order.grandTotal}
                                currency={currency}
                            />
                        </span>
                    </div>
                    <div className="flex flex-col gap-0.5">
                        <span className="text-xs text-muted-foreground">
                            Allocated
                        </span>
                        <span className="font-heading text-base font-semibold text-foreground tabular-nums">
                            <MoneyText
                                amount={paid.toFixed(2)}
                                currency={currency}
                            />
                        </span>
                    </div>
                    <div className="flex flex-col gap-0.5 text-right">
                        <span className="text-xs text-muted-foreground">
                            {remaining < -0.005 ? 'Overpaid' : 'Remaining'}
                        </span>
                        <span
                            className={`font-heading text-base font-semibold tabular-nums ${
                                Math.abs(remaining) <= 0.005
                                    ? 'text-success'
                                    : remaining < -0.005
                                      ? 'text-destructive'
                                      : 'text-foreground'
                            }`}
                        >
                            <MoneyText
                                amount={Math.abs(remaining).toFixed(2)}
                                currency={currency}
                            />
                        </span>
                    </div>
                </div>
                {otherBills.length > 0 && (
                    <div
                        role="alert"
                        className="flex flex-col gap-2 rounded-lg border border-warning bg-warning/10 p-3"
                    >
                        <p className="font-medium text-warning-text">
                            This table has {otherBills.length + 1} open bills.
                        </p>
                        <ul className="flex flex-col gap-1 text-sm">
                            {otherBills.map((bill) => (
                                <li
                                    key={bill.id}
                                    className="flex justify-between gap-2"
                                >
                                    <span>
                                        {bill.label} · opened{' '}
                                        {new Date(
                                            bill.openedAt,
                                        ).toLocaleTimeString([], {
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        })}
                                    </span>
                                    <MoneyText
                                        amount={bill.total}
                                        currency={currency}
                                    />
                                </li>
                            ))}
                        </ul>
                        <label className="flex items-center gap-3">
                            <Checkbox
                                checked={billsChecked}
                                onCheckedChange={(value) =>
                                    setBillsChecked(value === true)
                                }
                            />
                            <span>I am paying only this bill</span>
                        </label>
                    </div>
                )}
                <div className="flex items-end gap-2">
                    <Field className="w-28">
                        <FieldLabel htmlFor="split-people">
                            Split evenly
                        </FieldLabel>
                        <Input
                            id="split-people"
                            type="number"
                            min={2}
                            max={20}
                            value={people}
                            onChange={(event) => setPeople(event.target.value)}
                        />
                    </Field>
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        onClick={() => {
                            const count = Math.min(
                                20,
                                Math.max(2, Number(people) || 2),
                            );
                            const cents = Math.round(total * 100);
                            const share = Math.floor(cents / count);
                            setTenders(
                                Array.from({ length: count }, (_, at) =>
                                    blank(
                                        first?.tenderType ?? 'cash',
                                        (
                                            (at === count - 1
                                                ? cents - share * (count - 1)
                                                : share) / 100
                                        ).toFixed(2),
                                    ),
                                ),
                            );
                        }}
                    >
                        Share the bill
                    </Button>
                </div>
                {tenders.map((tender, index) => {
                    const rowRounding =
                        tender.tender_type === 'cash' &&
                        index === firstCashIndex
                            ? totalRounding
                            : 0;
                    const cashDue =
                        Math.round(
                            ((Number(tender.amount) || 0) + rowRounding) * 100,
                        ) / 100;
                    const given = Number(tender.tendered_amount);
                    const change =
                        tender.tender_type === 'cash' &&
                        tender.tendered_amount !== '' &&
                        given > cashDue
                            ? Math.round((given - cashDue) * 100) / 100
                            : 0;
                    const typeMethods = methods.filter(
                        (method) => method.tenderType === tender.tender_type,
                    );

                    return (
                        <div
                            key={index}
                            className="flex flex-col gap-3 rounded-lg border p-3"
                        >
                            {tenders.length > 1 && (
                                <p className="text-xs font-medium text-muted-foreground">
                                    Payment {index + 1} of {tenders.length}
                                </p>
                            )}
                            <div className="flex items-end gap-2">
                                <Field className="flex-1">
                                    <FieldLabel
                                        htmlFor={`tender-type-${index}`}
                                    >
                                        Pay with
                                    </FieldLabel>
                                    <EnumSelect
                                        id={`tender-type-${index}`}
                                        name={`tenders[${index}][tender_type]`}
                                        options={types.map((type) => ({
                                            value: type,
                                            label: tenderLabel(type),
                                        }))}
                                        value={tender.tender_type}
                                        onValueChange={(value) =>
                                            patch(index, {
                                                tender_type: value,
                                                payment_method_id: '',
                                                tendered_amount: '',
                                                reservation_unit_id:
                                                    value === 'room_charge' &&
                                                    order.reservationUnitId !==
                                                        null
                                                        ? String(
                                                              order.reservationUnitId,
                                                          )
                                                        : '',
                                                guest_label:
                                                    value === 'room_charge' &&
                                                    order.roomLabel !== null
                                                        ? order.roomLabel
                                                        : '',
                                            })
                                        }
                                    />
                                </Field>
                                <Field className="w-32">
                                    <FieldLabel
                                        htmlFor={`tender-amount-${index}`}
                                    >
                                        Amount
                                    </FieldLabel>
                                    <Input
                                        id={`tender-amount-${index}`}
                                        inputMode="decimal"
                                        className="tabular-nums"
                                        value={tender.amount}
                                        onChange={(event) =>
                                            patch(index, {
                                                amount: event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                {tenders.length > 1 && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-11"
                                        aria-label="Remove this payment"
                                        onClick={() =>
                                            setTenders((current) =>
                                                current.filter(
                                                    (_, at) => at !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 aria-hidden="true" />
                                    </Button>
                                )}
                            </div>
                            {typeMethods.length > 1 && (
                                <EnumSelect
                                    id={`tender-method-${index}`}
                                    name={`tenders[${index}][payment_method_id]`}
                                    options={typeMethods.map((method) => ({
                                        value: String(method.paymentMethodId),
                                        label: method.name,
                                    }))}
                                    value={tender.payment_method_id}
                                    onValueChange={(value) =>
                                        patch(index, {
                                            payment_method_id: value,
                                        })
                                    }
                                    placeholder="Payment method"
                                />
                            )}
                            {tender.tender_type === 'cash' && (
                                <Field>
                                    <FieldLabel
                                        htmlFor={`tender-given-${index}`}
                                    >
                                        Cash handed over
                                        {rowRounding !== 0 && (
                                            <span className="ms-1 text-muted-foreground">
                                                (due{' '}
                                                <MoneyText
                                                    amount={cashDue.toFixed(2)}
                                                    currency={currency}
                                                />
                                                )
                                            </span>
                                        )}
                                    </FieldLabel>
                                    <Input
                                        id={`tender-given-${index}`}
                                        inputMode="decimal"
                                        className="h-11 tabular-nums"
                                        value={tender.tendered_amount}
                                        onChange={(event) =>
                                            patch(index, {
                                                tendered_amount:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                    <div className="flex flex-wrap gap-1.5 pt-1">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="min-h-11"
                                            onClick={() =>
                                                patch(index, {
                                                    tendered_amount:
                                                        cashDue.toFixed(2),
                                                })
                                            }
                                        >
                                            Exact
                                        </Button>
                                        {QUICK_CASH_BILLS.map((bill) => (
                                            <Button
                                                key={bill}
                                                type="button"
                                                variant="outline"
                                                className="min-h-11 tabular-nums"
                                                onClick={() =>
                                                    patch(index, {
                                                        tendered_amount:
                                                            bill.toFixed(2),
                                                    })
                                                }
                                            >
                                                {currency?.code ?? ''} {bill}
                                            </Button>
                                        ))}
                                    </div>
                                    {change > 0 && (
                                        <div className="mt-1 flex items-baseline justify-between gap-2 rounded-lg border border-primary/30 bg-primary/10 px-3 py-2.5">
                                            <span className="text-sm font-medium text-foreground">
                                                Change due
                                            </span>
                                            <span className="font-heading text-xl font-semibold text-primary tabular-nums">
                                                <MoneyText
                                                    amount={change.toFixed(2)}
                                                    currency={currency}
                                                />
                                            </span>
                                        </div>
                                    )}
                                </Field>
                            )}
                            {tender.tender_type === 'room_charge' && (
                                <RoomChargeGuestPicker
                                    index={index}
                                    selectedUnitId={tender.reservation_unit_id}
                                    selectedLabel={tender.guest_label}
                                    onSelect={(unitId, label) =>
                                        patch(index, {
                                            reservation_unit_id: unitId,
                                            guest_label: label,
                                        })
                                    }
                                />
                            )}
                            {[
                                'card',
                                'e_wallet',
                                'duitnow_qr',
                                'payment_voucher',
                            ].includes(tender.tender_type) && (
                                <Field>
                                    <FieldLabel htmlFor={`tender-ref-${index}`}>
                                        Reference
                                    </FieldLabel>
                                    <Input
                                        id={`tender-ref-${index}`}
                                        value={tender.reference}
                                        onChange={(event) =>
                                            patch(index, {
                                                reference: event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                            )}
                        </div>
                    );
                })}
                {remaining > 0.005 && (
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        onClick={() =>
                            setTenders((current) => [
                                ...current,
                                blank('cash', remaining.toFixed(2)),
                            ])
                        }
                    >
                        <Plus data-icon="inline-start" />
                        Split:{' '}
                        <MoneyText
                            amount={remaining.toFixed(2)}
                            currency={currency}
                        />{' '}
                        left
                    </Button>
                )}
                {remaining < -0.005 && (
                    <p role="alert" className="text-sm text-destructive">
                        Tenders exceed the bill by{' '}
                        <MoneyText
                            amount={Math.abs(remaining).toFixed(2)}
                            currency={currency}
                        />
                        .
                    </p>
                )}
                {hasRoom && requireRoomChargeSignature && (
                    <label className="flex min-h-11 items-center gap-3">
                        <Checkbox
                            checked={signed}
                            onCheckedChange={(value) =>
                                setSigned(value === true)
                            }
                        />
                        <span>The guest signed the room charge slip</span>
                    </label>
                )}
                {hasMealCover && requireMealRedemptionConfirmation && (
                    <label className="flex min-h-11 items-center gap-3">
                        <Checkbox
                            checked={meal}
                            onCheckedChange={(value) => setMeal(value === true)}
                        />
                        <span>The guest confirmed the included meal</span>
                    </label>
                )}
                {hasRoom && overRoomLimit && (
                    <PosApprovalFields
                        required
                        staff={staff}
                        data={approval}
                        onChange={setApproval}
                    />
                )}
            </div>
        </FormOverlay>
    );
}
