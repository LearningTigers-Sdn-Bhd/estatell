import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
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
    reference: string;
};

type Guest = {
    reservation_unit_id: number;
    room_number: string;
    guest_name: string;
    can_post_charges: boolean;
};

const TENDER_LABELS: Record<string, string> = {
    cash: 'Cash',
    card: 'Card',
    e_wallet: 'E-wallet',
    duitnow_qr: 'DuitNow QR',
    payment_voucher: 'Voucher',
    room_charge: 'Charge to room',
};

/**
 * Take payment: one or more tenders. The server enforces the exact total; cash may be handed over above it and the
 * change shown here is only for the cashier to read out.
 */
export function PosCheckoutSheet({
    open,
    onOpenChange,
    order,
    currency,
    methods,
    staff,
    hasMealCover,
    otherBills = [],
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    order: App.Data.Pos.PosOrderData;
    currency: PosCurrency;
    methods: PosPaymentMethod[];
    staff: PosStaff[];
    hasMealCover: boolean;
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
        reservation_unit_id: '',
        reference: '',
    });
    const [tenders, setTenders] = useState<Tender[]>([
        blank(first?.tenderType ?? 'cash', order.grandTotal),
    ]);
    const [signed, setSigned] = useState(false);
    const [meal, setMeal] = useState(false);
    const [approval, setApproval] = useState<ApprovalData>(noApproval);
    const [guests, setGuests] = useState<Guest[]>([]);
    const [search, setSearch] = useState('');
    const [processing, setProcessing] = useState(false);
    const [billsChecked, setBillsChecked] = useState(false);
    const [people, setPeople] = useState('2');
    const [errors, setErrors] = useState<Record<string, string>>({});

    const types = Array.from(
        new Set([...methods.map((method) => method.tenderType), 'room_charge']),
    );
    const total = Number(order.grandTotal);
    const paid = tenders.reduce(
        (sum, tender) => sum + (Number(tender.amount) || 0),
        0,
    );
    const remaining = Math.round((total - paid) * 100) / 100;

    function patch(index: number, change: Partial<Tender>) {
        setTenders((current) =>
            current.map((tender, at) =>
                at === index ? { ...tender, ...change } : tender,
            ),
        );
    }

    async function findGuests(term: string) {
        setSearch(term);

        if (term.trim().length < 2) {
            setGuests([]);

            return;
        }

        const response = await fetch(
            inHouseGuests.url(undefined, { query: { q: term } }),
            { headers: { Accept: 'application/json' } },
        );
        const body = (await response.json()) as { guests: Guest[] };
        setGuests(body.guests);
    }

    function submit() {
        router.post(
            settle.url({ order: order.id }),
            {
                tenders: tenders.map((tender) => ({
                    tender_type: tender.tender_type,
                    amount: tender.amount,
                    payment_method_id: tender.payment_method_id || undefined,
                    tendered_amount: tender.tendered_amount || undefined,
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

    const hasRoom = tenders.some(
        (tender) => tender.tender_type === 'room_charge',
    );

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={onOpenChange}
            title="Take payment"
            description={
                <>
                    Total{' '}
                    <MoneyText amount={order.grandTotal} currency={currency} />
                </>
            }
            errors={errors}
            processing={processing}
            submitLabel="Complete payment"
            submitDisabled={
                (otherBills.length > 0 && !billsChecked) ||
                (remaining !== 0 &&
                    !tenders.some((tender) => tender.tender_type === 'cash'))
            }
            onSubmit={submit}
        >
            <div className="flex flex-col gap-4">
                {otherBills.length > 0 && (
                    <div
                        role="alert"
                        className="flex flex-col gap-2 rounded-lg border border-warning p-3"
                    >
                        <p className="font-medium">
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
                    const given = Number(tender.tendered_amount);
                    const change =
                        tender.tender_type === 'cash' &&
                        given > Number(tender.amount)
                            ? given - Number(tender.amount)
                            : 0;
                    const typeMethods = methods.filter(
                        (method) => method.tenderType === tender.tender_type,
                    );

                    return (
                        <div
                            key={index}
                            className="flex flex-col gap-3 rounded-lg border p-3"
                        >
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
                                            label: TENDER_LABELS[type] ?? type,
                                        }))}
                                        value={tender.tender_type}
                                        onValueChange={(value) =>
                                            patch(index, {
                                                tender_type: value,
                                                payment_method_id: '',
                                                tendered_amount: '',
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
                                        aria-label="Remove this payment"
                                        onClick={() =>
                                            setTenders((current) =>
                                                current.filter(
                                                    (_, at) => at !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 />
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
                                    </FieldLabel>
                                    <Input
                                        id={`tender-given-${index}`}
                                        inputMode="decimal"
                                        className="tabular-nums"
                                        value={tender.tendered_amount}
                                        onChange={(event) =>
                                            patch(index, {
                                                tendered_amount:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                    {change > 0 && (
                                        <p className="text-lg font-semibold">
                                            Change{' '}
                                            <MoneyText
                                                amount={change.toFixed(2)}
                                                currency={currency}
                                            />
                                        </p>
                                    )}
                                </Field>
                            )}
                            {tender.tender_type === 'room_charge' && (
                                <Field>
                                    <FieldLabel
                                        htmlFor={`tender-room-${index}`}
                                    >
                                        In-house guest
                                    </FieldLabel>
                                    <Input
                                        id={`tender-room-${index}`}
                                        placeholder="Room number or guest name"
                                        value={search}
                                        onChange={(event) =>
                                            void findGuests(event.target.value)
                                        }
                                    />
                                    <div className="flex flex-col gap-1">
                                        {guests.map((guest) => (
                                            <Button
                                                key={guest.reservation_unit_id}
                                                type="button"
                                                variant={
                                                    tender.reservation_unit_id ===
                                                    String(
                                                        guest.reservation_unit_id,
                                                    )
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                disabled={
                                                    !guest.can_post_charges
                                                }
                                                onClick={() =>
                                                    patch(index, {
                                                        reservation_unit_id:
                                                            String(
                                                                guest.reservation_unit_id,
                                                            ),
                                                    })
                                                }
                                            >
                                                {guest.room_number} ·{' '}
                                                {guest.guest_name}
                                                {!guest.can_post_charges &&
                                                    ' (cash only)'}
                                            </Button>
                                        ))}
                                    </div>
                                </Field>
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
                {remaining > 0 && (
                    <Button
                        type="button"
                        variant="outline"
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
                {hasRoom && (
                    <label className="flex items-center gap-3">
                        <Checkbox
                            checked={signed}
                            onCheckedChange={(value) =>
                                setSigned(value === true)
                            }
                        />
                        <span>The guest signed the slip</span>
                    </label>
                )}
                {hasMealCover && (
                    <label className="flex items-center gap-3">
                        <Checkbox
                            checked={meal}
                            onCheckedChange={(value) => setMeal(value === true)}
                        />
                        <span>The guest confirmed the included meal</span>
                    </label>
                )}
                {hasRoom && (
                    <PosApprovalFields
                        staff={staff}
                        data={approval}
                        onChange={setApproval}
                    />
                )}
            </div>
        </FormOverlay>
    );
}
