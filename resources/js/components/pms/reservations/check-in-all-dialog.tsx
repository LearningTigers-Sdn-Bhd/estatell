import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { formatMoney } from '@/components/pms/money-text';
import {
    ChargeGroup,
    CollectRow,
    fullBillAmount,
    isCollectable,
    payableSum,
    isTickedByDefault,
    TaxRow,
} from '@/components/pms/reservations/collect-rows';
import type {
    ChargeLine,
    TaxLine,
} from '@/components/pms/reservations/collect-rows';
import {
    EndedStayColumns,
    EndedStayQuestion,
} from '@/components/pms/reservations/ended-stay-question';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { checkInAll, checkInAllPreview } from '@/routes/reservations';

type Preview = App.Data.Reservations.CheckInAllPreviewData;
type Collection = App.Data.Reservations.CheckInCollectionData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservation: App.Data.Reservations.ReservationData;
    paymentOptions?: App.Data.Payments.PaymentFormOptionsData;
    currency: App.Data.Property.CurrencyData | null;
    /** Opens the group Taxes paid elsewhere with the ticked rooms. Without it, the tax rows offer no link. */
    onWaiveTax?: (unitIds: number[]) => void;
};

/**
 * The tax rows of the ticked rooms added up by tax, display only. Exempt rooms are left out of the label: when every
 * other room shows the same thing (the same payer of a waiver, or the same Master folio or company), the row says so,
 * and when every room is exempt it says Exempt. Otherwise it shows the sum the
 * rooms pay at check-in. The link shows when any room can mark the tax as paid elsewhere.
 */
function sumTaxes(collections: Collection[]): TaxLine[] {
    const byTax = new Map<number, TaxLine[]>();

    for (const collection of collections) {
        for (const tax of collection.taxes) {
            byTax.set(tax.taxId, [...(byTax.get(tax.taxId) ?? []), tax]);
        }
    }

    return [...byTax.entries()].map(([taxId, rows]) => {
        // An exempt room pays nothing and says nothing about where the tax goes, so the label comes from the others.
        const paying = rows.filter((row) => !row.exempt);
        const same = (pick: (row: TaxLine) => string | null) =>
            paying.length > 0 &&
            paying.every(
                (row) => pick(row) !== null && pick(row) === pick(paying[0]),
            )
                ? pick(paying[0])
                : null;
        const waivedBy = same((row) => row.waivedBy);
        const chargedTo = same((row) => row.chargedTo);
        // Every paying room puts the tax on a Master no company pays: one tick pays it into the Master for each room.
        const payableOnMaster =
            waivedBy === null &&
            paying.length > 0 &&
            paying.every((row) => row.payableOnMaster);
        const collected = paying
            .filter(
                (row) =>
                    row.waivedBy === null &&
                    (row.chargedTo === null || payableOnMaster),
            )
            .reduce((sum, row) => sum + Number(row.amount), 0);

        return {
            taxId,
            name: rows[0].name,
            amount: String(collected),
            exempt: paying.length === 0,
            waivedBy,
            chargedTo: waivedBy === null ? chargedTo : null,
            payableOnMaster,
            waivable: rows.some((row) => row.waivable),
        };
    });
}

/** The unit-folio charges of the ticked rooms added up by charge, display only: "Speedboat fare · 4×". */
function sumCharges(lists: ChargeLine[][]): ChargeLine[] {
    const byCode = new Map<number, ChargeLine>();

    for (const charge of lists.flat()) {
        const seen = byCode.get(charge.codeId);
        const count = (seen?.count ?? 0) + charge.count;
        const amount = Number(seen?.amount ?? 0) + Number(charge.amount);

        byCode.set(charge.codeId, {
            ...charge,
            count,
            amount: String(amount),
            payable: (seen?.payable ?? true) && charge.payable,
            holdsTaxes: (seen?.holdsTaxes ?? true) && charge.holdsTaxes,
            chargedTo:
                seen === undefined || seen.chargedTo === charge.chargedTo
                    ? charge.chargedTo
                    : null,
            label: charge.perNight
                ? `Room · ${count} ${count === 1 ? 'night' : 'nights'}`
                : count > 1
                  ? `${charge.name} · ${count}×`
                  : charge.name,
        });
    }

    return [...byCode.values()].sort(
        (a, b) => Number(b.isStay) - Number(a.isStay),
    );
}

function CheckInAllForm({
    open,
    onOpenChange,
    reservation,
    paymentOptions,
    currency,
    onWaiveTax,
}: Props) {
    const key = { reservation: reservation.id };
    const { preview, reload } = useUnitPreview<Preview>(
        checkInAllPreview.url(key),
        open,
    );
    const canCollect =
        preview?.rows.some((row) => row.collection !== null) ?? false;
    useFormOptions(
        open && canCollect,
        paymentOptions !== undefined,
        'paymentOptions',
    );
    const [picked, setPicked] = useState<number[] | null>(null);
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const form = useForm({
        without_card: false,
        ended_stay_choice: 'arrived_last_night',
        arrived_at: '',
        collect_now: true,
        charge_ids: [] as number[],
        collect_deposit: true,
        tax_ticks: {} as Record<number, boolean>,
        payment_method_id: '',
        reference: '',
    });
    const { data, errors } = form;
    const rowErrors = errors as Record<string, string | undefined>;
    const ready = preview?.rows.filter((row) => row.ready) ?? [];
    const selected = (picked ?? ready.map((row) => row.unitId)).filter((id) =>
        ready.some((row) => row.unitId === id),
    );
    const nothingToCheckIn = preview !== null && preview.rows.length === 0;
    const noneReady =
        preview !== null && preview.rows.length > 0 && ready.length === 0;
    const needsCard =
        preview?.rows.some(
            (row) => selected.includes(row.unitId) && row.cardsMissing > 0,
        ) ?? false;

    const endedStay = preview?.endedStay ?? null;
    const endedRows = ready.filter(
        (row) => row.endedStay && selected.includes(row.unitId),
    );
    const isMixed = ready.some((row) => !row.endedStay);
    const arrivingToday =
        endedRows.length > 0 && data.ended_stay_choice === 'arriving_today';
    // Display only: the server prices each move again.
    const moveTotal = endedRows.reduce(
        (sum, row) => sum + Number(row.moveTotal ?? 0),
        0,
    );

    // Display only: the server works out each room's amounts again on submit.
    const methods = (paymentOptions?.methods ?? []).filter(
        (one) => !one.isOldSystem && !one.isOtaCollected,
    );
    const methodId = data.payment_method_id || String(methods[0]?.id ?? '');
    const method = methods.find((one) => String(one.id) === methodId);
    const collections = ready
        .filter((row) => selected.includes(row.unitId))
        .map((row) => row.collection)
        .filter((one): one is Collection => one !== null);
    const taxes = sumTaxes(collections);
    // Each tax the guests pay here has its own tick; the server adds up the ticked ones for each room again.
    const collectableTaxes = taxes.filter(isCollectable);
    const tickedTaxes = collectableTaxes.filter(
        (tax) => data.tax_ticks[tax.taxId] ?? isTickedByDefault(tax),
    );
    const taxesDue = collectableTaxes.reduce(
        (sum, tax) => sum + Number(tax.amount),
        0,
    );
    const taxesTicked = tickedTaxes.reduce(
        (sum, tax) => sum + Number(tax.amount),
        0,
    );
    const depositRooms = collections.filter((one) => one.deposit !== null);
    const depositTotal = depositRooms.reduce(
        (sum, one) => sum + Number(one.deposit),
        0,
    );
    // Display only: the Full bill of the ticked rooms, added up by charge. A move to today changes the bill, so the
    // old charges are not offered. Each room pays its own folio at most what it owes, and the Master's part never
    // passes what the Master still owes. The server works it all out again.
    const billRooms = arrivingToday ? [] : collections;
    const charges = sumCharges(billRooms.map((one) => one.charges));
    const masterOwed = Number(
        billRooms.find((one) => one.masterOwed !== null)?.masterOwed ?? 0,
    );
    const billFor = (ids: number[] | null) =>
        billRooms.reduce((sum, one) => sum + fullBillAmount(one, ids).unit, 0) +
        Math.min(
            masterOwed,
            billRooms.reduce(
                (sum, one) => sum + payableSum(one.charges, 'master', ids),
                0,
            ),
        );
    const chargesOwed = billFor(null);
    const chargesShown = charges.length > 0;
    const chargesOffered = chargesOwed > 0;
    const tickedIds = data.charge_ids.filter((id) =>
        charges.some((charge) => charge.payable && charge.codeId === id),
    );
    const chargesAmount = billFor(tickedIds);
    const chargesPaid =
        billRooms.reduce((sum, one) => sum + Number(one.chargesPaid), 0) +
        (charges.some((charge) => charge.payable && charge.folio === 'master')
            ? Number(billRooms[0]?.masterPaid ?? 0)
            : 0);
    // A ticked Room that holds the collected taxes already includes the tax rows, so they drop out.
    const billTicked = charges.some(
        (charge) => charge.holdsTaxes && tickedIds.includes(charge.codeId),
    );
    const collectTotal =
        chargesAmount +
        (billTicked ? 0 : taxesTicked) +
        (data.collect_deposit ? depositTotal : 0);
    const showCollect = collections.length > 0;
    const nothingToCollect =
        showCollect &&
        taxesDue === 0 &&
        depositRooms.length === 0 &&
        !chargesOffered;
    const collecting = showCollect && !nothingToCollect;

    const toggle = (unitId: number, checked: boolean) =>
        setPicked(
            checked
                ? [...selected, unitId]
                : selected.filter((id) => id !== unitId),
        );

    const submit = () => {
        form.transform((values) => ({
            ...values,
            unit_ids: selected,
            ended_stay_choice:
                endedRows.length > 0 ? values.ended_stay_choice : '',
            arrived_at:
                endedRows.length > 0 && !arrivingToday ? values.arrived_at : '',
            collect_now: collecting && values.collect_now,
            collect_taxes: collecting && values.collect_now,
            collect_tax_ids: tickedTaxes.map((tax) => tax.taxId),
            tax_ticks: undefined,
            room_charges: false,
            collect_charge_ids:
                collecting && values.collect_now ? tickedIds : [],
            charge_ids: undefined,
            collect_deposit:
                depositRooms.length > 0 &&
                values.collect_now &&
                values.collect_deposit,
            payment_method_id: collecting ? methodId : '',
            reference: method?.requiresReference ? values.reference : '',
            idempotency_key: idempotencyKey,
        }));
        form.post(checkInAll.url(key), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
            onError: () => {
                // Units that were checked in leave the list, so the ones that failed stay.
                setPicked(selected);
                reload();
            },
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            size={endedStay !== null ? 'lg' : undefined}
            open={open}
            onOpenChange={onOpenChange}
            title="Check in all"
            description={`${reservation.reference} · ${reservation.title}`}
            errors={errors}
            onSubmit={submit}
            submitLabel={
                selected.length === 0
                    ? 'Check in'
                    : selected.length === 1
                      ? 'Check in 1 unit'
                      : `Check in ${selected.length} units`
            }
            processing={form.processing}
            submitDisabled={
                selected.length === 0 ||
                (endedRows.length > 0 &&
                    !arrivingToday &&
                    data.arrived_at === '') ||
                (data.collect_now && collecting && paymentOptions === undefined)
            }
            loading={preview === null}
            footer={
                nothingToCheckIn ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                ) : undefined
            }
        >
            {preview !== null && (
                <>
                    {preview.error !== null && (
                        <p className="text-sm text-destructive">
                            {preview.error}
                        </p>
                    )}
                    {preview.rows.length === 0 && preview.error === null && (
                        <p className="text-sm text-muted-foreground">
                            No unit is waiting to check in.
                        </p>
                    )}
                    {noneReady && (
                        <p className="text-sm font-medium">
                            No guest in this booking can check in today. The
                            reason is under each name.
                        </p>
                    )}
                    <EndedStayColumns
                        question={
                            endedStay !== null ? (
                                <EndedStayQuestion
                                    id="check-in-all"
                                    plural
                                    intro={
                                        endedStay.oneNight
                                            ? `This booking was for last night (${endedStay.lastNight}) and was not checked in.`
                                            : `This booking's last night was ${endedStay.lastNight} and it was not checked in.`
                                    }
                                    legend="Do you want to check the guests in for the previous business day?"
                                    keepLabel="Yes, they arrived after night audit was done"
                                    moveLabel="No, they arrive today"
                                    stayedDescription={`Keeps ${endedStay.stayDates}. The guests came for the booked ${endedStay.oneNight ? 'night' : 'nights'}. This includes arriving after midnight, such as 2:00 a.m. today. ${endedStay.oneNight ? 'That night is' : 'Those nights are'} charged for each room.`}
                                    arrivingDescription={
                                        endedStay.arrivingTodayBlocked ??
                                        `Changes the ticked rooms to ${endedStay.today} → ${endedStay.tomorrow}. Only if the guests have not used the rooms yet and will sleep here tonight. They leave tomorrow. New total ${formatMoney(String(moveTotal), currency)}.`
                                    }
                                    arrivingDisabled={
                                        endedStay.arrivingTodayBlocked !== null
                                    }
                                    choice={data.ended_stay_choice}
                                    onChoiceChange={(value) =>
                                        form.setData('ended_stay_choice', value)
                                    }
                                    arrivedAt={data.arrived_at}
                                    onArrivedAtChange={(value) =>
                                        form.setData('arrived_at', value)
                                    }
                                    errors={errors}
                                />
                            ) : null
                        }
                    >
                        {preview.rows.length > 0 && (
                            <div className="flex flex-col divide-y rounded-lg border">
                                {preview.rows.map((row) => {
                                    const error =
                                        rowErrors[`units.${row.unitId}`];

                                    return (
                                        <Field
                                            key={row.unitId}
                                            orientation="horizontal"
                                            className="p-3"
                                            data-invalid={!!error}
                                            data-disabled={!row.ready}
                                        >
                                            <Checkbox
                                                id={`check-in-all-${row.unitId}`}
                                                checked={selected.includes(
                                                    row.unitId,
                                                )}
                                                disabled={!row.ready}
                                                onCheckedChange={(checked) =>
                                                    toggle(row.unitId, checked)
                                                }
                                            />
                                            <FieldContent>
                                                <FieldLabel
                                                    htmlFor={`check-in-all-${row.unitId}`}
                                                >
                                                    {row.label}
                                                    {row.room
                                                        ? ` · ${row.room}`
                                                        : ''}
                                                    {row.guest
                                                        ? ` · ${row.guest}`
                                                        : ''}
                                                </FieldLabel>
                                                {isMixed && row.endedStay && (
                                                    <FieldDescription>
                                                        Late room: uses the
                                                        answer above.
                                                    </FieldDescription>
                                                )}
                                                {row.note !== null && (
                                                    <FieldDescription>
                                                        {row.note}
                                                    </FieldDescription>
                                                )}
                                                {row.cardsMissing > 0 &&
                                                    row.ready && (
                                                        <span className="text-sm text-warning">
                                                            {row.cardsMissing ===
                                                            1
                                                                ? '1 card not signed'
                                                                : `${row.cardsMissing} cards not signed`}
                                                        </span>
                                                    )}
                                                <FieldError>{error}</FieldError>
                                            </FieldContent>
                                        </Field>
                                    );
                                })}
                            </div>
                        )}
                        {needsCard && preview.canCheckInWithoutCard && (
                            <Field orientation="horizontal">
                                <Checkbox
                                    id="check-in-all-without_card"
                                    checked={data.without_card}
                                    onCheckedChange={(checked) =>
                                        form.setData('without_card', checked)
                                    }
                                />
                                <FieldContent>
                                    <FieldLabel htmlFor="check-in-all-without_card">
                                        Check in without a card
                                    </FieldLabel>
                                    <FieldDescription>
                                        Cards stay unsigned. This is logged with
                                        your name.
                                    </FieldDescription>
                                </FieldContent>
                            </Field>
                        )}
                        {showCollect && (
                            <div className="flex flex-col gap-2 rounded-lg border p-3 text-sm">
                                <span className="font-medium">
                                    To collect ·{' '}
                                    {collections.length === 1
                                        ? '1 room'
                                        : `${collections.length} rooms`}
                                </span>
                                {!billTicked &&
                                    taxes.map((tax) => (
                                        <TaxRow
                                            key={tax.taxId}
                                            idPrefix="check-in-all"
                                            tax={tax}
                                            currency={currency}
                                            checked={
                                                data.tax_ticks[tax.taxId] ??
                                                isTickedByDefault(tax)
                                            }
                                            onCheckedChange={(checked) =>
                                                form.setData('tax_ticks', {
                                                    ...data.tax_ticks,
                                                    [tax.taxId]: checked,
                                                })
                                            }
                                            onWaiveTax={
                                                onWaiveTax === undefined
                                                    ? undefined
                                                    : () => onWaiveTax(selected)
                                            }
                                        />
                                    ))}
                                {depositRooms.length > 0 && (
                                    <CollectRow
                                        id="check-in-all-collect_deposit"
                                        label="Security deposit"
                                        amount={String(depositTotal)}
                                        currency={currency}
                                        checked={data.collect_deposit}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'collect_deposit',
                                                checked,
                                            )
                                        }
                                    />
                                )}
                                {chargesShown && (
                                    <ChargeGroup
                                        idPrefix="check-in-all"
                                        charges={charges}
                                        owed={chargesOwed}
                                        paid={chargesPaid}
                                        ticked={data.charge_ids}
                                        onTickedChange={(ticked) =>
                                            form.setData('charge_ids', ticked)
                                        }
                                        currency={currency}
                                    />
                                )}
                                {nothingToCollect ? (
                                    <p
                                        className="text-muted-foreground"
                                        data-slot="no-collection"
                                    >
                                        No collection is needed.
                                    </p>
                                ) : (
                                    <>
                                        <Separator className="-mx-3 data-horizontal:w-auto" />
                                        <CollectRow
                                            id="check-in-all-collect_now"
                                            label="Collect now"
                                            prefix="Total "
                                            amount={String(
                                                data.collect_now
                                                    ? collectTotal
                                                    : 0,
                                            )}
                                            currency={currency}
                                            checked={data.collect_now}
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'collect_now',
                                                    checked,
                                                )
                                            }
                                        />
                                    </>
                                )}
                            </div>
                        )}
                        {collecting && data.collect_now && (
                            <div
                                className={cn(
                                    'grid gap-4',
                                    method?.requiresReference &&
                                        'sm:grid-cols-2',
                                )}
                            >
                                <Field
                                    data-invalid={!!errors.payment_method_id}
                                >
                                    <FieldLabel htmlFor="check-in-all-payment_method_id">
                                        Method
                                    </FieldLabel>
                                    {paymentOptions === undefined ? (
                                        <Skeleton className="h-9 w-full" />
                                    ) : (
                                        <EnumSelect
                                            id="check-in-all-payment_method_id"
                                            options={methods.map((one) => ({
                                                value: String(one.id),
                                                label: one.name,
                                            }))}
                                            value={methodId}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'payment_method_id',
                                                    value,
                                                )
                                            }
                                            aria-invalid={
                                                !!errors.payment_method_id
                                            }
                                        />
                                    )}
                                    <FieldError>
                                        {errors.payment_method_id}
                                    </FieldError>
                                </Field>
                                {method?.requiresReference && (
                                    <Field data-invalid={!!errors.reference}>
                                        <FieldLabel htmlFor="check-in-all-reference">
                                            Reference
                                        </FieldLabel>
                                        <Input
                                            id="check-in-all-reference"
                                            placeholder="e.g. 4821 0933"
                                            value={data.reference}
                                            onChange={(event) =>
                                                form.setData(
                                                    'reference',
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={100}
                                            autoComplete="off"
                                            aria-invalid={!!errors.reference}
                                        />
                                        <FieldError>
                                            {errors.reference}
                                        </FieldError>
                                    </Field>
                                )}
                            </div>
                        )}
                    </EndedStayColumns>
                </>
            )}
        </FormOverlay>
    );
}

/**
 * Checks in several units of a reservation in one step. A unit that cannot be checked in shows its reason on its own
 * row and does not stop the others. For a user with `payment.record`, one To collect list adds up the ticked rooms
 * (taxes, deposits, the room folio charges by charge) and each room records its own payments with the same method. A tax row's
 * Paid elsewhere… opens the group Taxes paid elsewhere with the ticked rooms.
 */
export function CheckInAllDialog(props: Props) {
    return <CheckInAllForm key={useFreshOnOpen(props.open)} {...props} />;
}
