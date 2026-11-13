import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CountrySelect } from '@/components/pms/country-select';
import { DateText } from '@/components/pms/date-text';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { formatMoney } from '@/components/pms/money-text';
import { MyKadScanner } from '@/components/pms/mykad-scanner';
import {
    ChargeGroup,
    CollectRow,
    fullBillAmount,
    isCollectable,
    isTickedByDefault,
    TaxRow,
} from '@/components/pms/reservations/collect-rows';
import {
    AuditPendingNote,
    AuditPendingStrip,
} from '@/components/pms/reservations/audit-pending';
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
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { checkIn, checkInPreview } from '@/routes/reservations/units';

type Unit = App.Data.Reservations.ReservationUnitData;
type Preview = App.Data.Reservations.UnitCheckInPreviewData;
type Options = App.Data.Payments.PaymentFormOptionsData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
    paymentOptions?: Options;
    canAssignRoom: boolean;
    onAssignRoom: () => void;
    onNoShow?: () => void;
    /** Opens Taxes paid elsewhere for the unit. Without it, the tax rows offer no link. */
    onWaiveTax?: () => void;
    currency: App.Data.Property.CurrencyData | null;
};

function CheckInForm({
    open,
    onOpenChange,
    reservationId,
    unit,
    paymentOptions,
    canAssignRoom,
    onAssignRoom,
    onNoShow,
    onWaiveTax,
    currency,
}: Props) {
    useFormOptions(open, paymentOptions !== undefined, 'paymentOptions');

    const key = { reservation: reservationId, unit: unit.id };
    const { preview } = useUnitPreview<Preview>(checkInPreview.url(key), open);
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const methods = (paymentOptions?.methods ?? []).filter(
        (one) => !one.isOldSystem && !one.isOtaCollected,
    );
    const form = useForm({
        ended_stay_choice: 'arrived_last_night',
        arrived_at: '',
        nationality_code: '',
        without_card: false,
        credit_override_reason: '',
        collect_now: true,
        charge_ids: [] as number[],
        collect_deposit: true,
        tax_ticks: {} as Record<number, boolean>,
        payment_method_id: '',
        reference: '',
    });
    const { data, errors } = form;
    const methodId = data.payment_method_id || String(methods[0]?.id ?? '');
    const method = methods.find((one) => String(one.id) === methodId);
    const collection = preview?.collection ?? null;
    const endedStay = preview?.endedStay ?? null;
    const arrivingToday =
        endedStay !== null && data.ended_stay_choice === 'arriving_today';
    // Display only: the Full bill summary (Room and other charges). The server works the amounts out again.
    // A move to today changes the bill, so the old charges are not offered.
    const charges = arrivingToday ? [] : (collection?.charges ?? []);
    const owedParts =
        collection === null
            ? { unit: 0, master: 0 }
            : fullBillAmount({ ...collection, charges }, null);
    const chargesOwed = owedParts.unit + owedParts.master;
    const chargesShown = charges.length > 0;
    const chargesOffered = chargesOwed > 0;
    const tickedCharges = charges.filter(
        (charge) => charge.payable && data.charge_ids.includes(charge.codeId),
    );
    const tickedParts =
        collection === null
            ? { unit: 0, master: 0 }
            : fullBillAmount(
                  { ...collection, charges },
                  tickedCharges.map((charge) => charge.codeId),
              );
    const chargesAmount = tickedParts.unit + tickedParts.master;
    const chargesPaidShown =
        Number(collection?.chargesPaid ?? 0) +
        (charges.some((charge) => charge.payable && charge.folio === 'master')
            ? Number(collection?.masterPaid ?? 0)
            : 0);
    // A ticked Room that holds the collected taxes already includes the tax rows, so they drop out.
    const billTicked = tickedCharges.some((charge) => charge.holdsTaxes);
    // Display only: the taxes the guest pays here, each with its own tick. The server adds up the ticked ones again.
    const collectableTaxes =
        collection === null ? [] : collection.taxes.filter(isCollectable);
    const tickedTaxes = collectableTaxes.filter(
        (tax) => data.tax_ticks[tax.taxId] ?? isTickedByDefault(tax),
    );
    const taxesDue = collectableTaxes.reduce(
        (sum, tax) => sum + Number(tax.amount),
        0,
    );
    const taxesTotal = billTicked
        ? 0
        : tickedTaxes.reduce((sum, tax) => sum + Number(tax.amount), 0);
    // Taxes on a Master folio the guest does not pay still show, as information, so staff can waive them.
    const taxRowsShown =
        collection !== null && collection.taxes.length > 0 && !billTicked;
    const billAmount = chargesAmount;
    const depositAmount =
        collection?.deposit != null && data.collect_deposit
            ? Number(collection.deposit)
            : 0;
    const collectTotal = taxesTotal + billAmount + depositAmount;
    // Rows can exist with nothing due (an exempt tax), so Collect now has nothing to take.
    const nothingToCollect =
        collection !== null &&
        taxesDue === 0 &&
        collection.deposit === null &&
        !chargesOffered;
    const collecting = collection !== null && !nothingToCollect;
    const needsOverride =
        preview !== null && preview.cardsMissing > 0 && !data.without_card;

    const submit = () => {
        form.transform((values) => ({
            ...values,
            ended_stay_choice:
                endedStay !== null ? values.ended_stay_choice : '',
            arrived_at:
                endedStay !== null && !arrivingToday ? values.arrived_at : '',
            payment_method_id: collecting ? methodId : '',
            reference: method?.requiresReference ? values.reference : '',
            collect_now: collecting && values.collect_now,
            collect_taxes: collecting && values.collect_now,
            // A tax paid into the Master has its own field, so a tax id alone never takes a Master part the form did not show.
            collect_tax_ids: tickedTaxes
                .filter((tax) => !tax.payableOnMaster)
                .map((tax) => tax.taxId),
            collect_master_tax_ids: tickedTaxes
                .filter((tax) => tax.payableOnMaster)
                .map((tax) => tax.taxId),
            tax_ticks: undefined,
            room_charges: false,
            collect_charge_ids:
                collecting && values.collect_now
                    ? tickedCharges.map((charge) => charge.codeId)
                    : [],
            charge_ids: undefined,
            collect_deposit:
                collection?.deposit != null &&
                values.collect_now &&
                values.collect_deposit,
            credit_override_reason: preview?.creditExceeded
                ? values.credit_override_reason
                : '',
            idempotency_key: idempotencyKey,
        }));
        form.post(checkIn.url(key), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="sheet"
            size={endedStay !== null ? 'lg' : undefined}
            open={open}
            onOpenChange={onOpenChange}
            dirty={form.isDirty}
            discardTitle="Discard this check-in?"
            title={`Check in · ${unit.roomType}`}
            description={
                <>
                    {unit.primaryGuest ?? 'No primary guest'} ·{' '}
                    <DateText date={unit.arrival} /> →{' '}
                    <DateText date={unit.departure} /> ·{' '}
                    {unit.stayLabel ??
                        `${unit.nightCount} ${unit.nightCount === 1 ? 'night' : 'nights'}`}
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel="Check in"
            processing={form.processing}
            submitDisabled={
                preview === null ||
                preview.error !== null ||
                preview.roomState === 'none' ||
                (endedStay !== null &&
                    !arrivingToday &&
                    data.arrived_at === '') ||
                (data.collect_now && collecting && paymentOptions === undefined)
            }
            loading={preview === null}
        >
            {preview !== null && (
                <>
                    <AuditPendingStrip pending={preview.auditPending} />
                    <AuditPendingNote pending={preview.auditPending} />
                    <EndedStayColumns
                        question={
                            endedStay !== null ? (
                                <EndedStayQuestion
                                    id="check-in"
                                    intro={
                                        unit.nightCount === 1
                                            ? `This booking was for last night (${endedStay.lastNight}) and was not checked in.`
                                            : `This booking's last night was ${endedStay.lastNight} and it was not checked in.`
                                    }
                                    legend="Do you want to check the guest in for the previous business day?"
                                    keepLabel="Yes, they arrived after night audit was done"
                                    moveLabel="No, they arrive today"
                                    stayedDescription={`Keeps ${endedStay.arrival} → ${endedStay.departure}. The guest came for the booked ${unit.nightCount === 1 ? 'night' : 'nights'}. This includes arriving after midnight, such as 2:00 a.m. today. ${unit.nightCount === 1 ? 'That night is' : 'Those nights are'} charged.`}
                                    arrivingDescription={
                                        endedStay.arrivingTodayBlocked ??
                                        `Changes the booking to ${endedStay.today} → ${endedStay.tomorrow}. Only if the guest has not used the room yet and will sleep here tonight. They leave tomorrow. New total ${formatMoney(endedStay.newTotal ?? '0', currency)}.`
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
                                    onNoShow={onNoShow}
                                />
                            ) : null
                        }
                    >
                        {preview.error !== null && (
                            <p className="text-sm text-destructive">
                                {preview.error}
                            </p>
                        )}
                        <div className="flex flex-col gap-1 text-sm">
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Room
                                </span>
                                <span>
                                    {preview.roomState === 'assigned' ? (
                                        preview.room
                                    ) : preview.roomState === 'auto' ? (
                                        <span className="text-muted-foreground">
                                            Assigned when you check in
                                        </span>
                                    ) : (
                                        <span className="text-destructive">
                                            {endedStay !== null
                                                ? 'Not assigned for this stay'
                                                : 'Not assigned for tonight'}
                                        </span>
                                    )}
                                </span>
                            </div>
                            {preview.roomState === 'none' && (
                                <div className="flex items-center justify-between gap-4 text-sm text-muted-foreground">
                                    {canAssignRoom
                                        ? 'Assign a room before you check in.'
                                        : 'Ask someone who can assign rooms.'}
                                    {canAssignRoom && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={onAssignRoom}
                                        >
                                            Assign a room
                                        </Button>
                                    )}
                                </div>
                            )}
                            {preview.cardRequired && (
                                <div className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">
                                        Registration card
                                    </span>
                                    <span
                                        className={
                                            preview.cardsMissing > 0
                                                ? 'text-warning'
                                                : 'text-success'
                                        }
                                    >
                                        {preview.cardsMissing === 0
                                            ? 'Signed'
                                            : preview.cardsMissing === 1
                                              ? '1 card not signed'
                                              : `${preview.cardsMissing} cards not signed`}
                                    </span>
                                </div>
                            )}
                        </div>
                        {preview.cardsMissing > 0 &&
                            preview.canCheckInWithoutCard && (
                                <Field orientation="horizontal">
                                    <Checkbox
                                        id="check-in-without_card"
                                        checked={data.without_card}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'without_card',
                                                checked,
                                            )
                                        }
                                    />
                                    <FieldContent>
                                        <FieldLabel htmlFor="check-in-without_card">
                                            Check in without a card
                                        </FieldLabel>
                                        <FieldDescription>
                                            The card stays unsigned. This is
                                            logged with your name.
                                        </FieldDescription>
                                    </FieldContent>
                                </Field>
                            )}
                        {needsOverride && !preview.canCheckInWithoutCard && (
                            <p className="text-sm text-muted-foreground">
                                Sign the registration card first, or ask someone
                                who may check in without one.
                            </p>
                        )}
                        {preview.needsNationality && (
                            <Field data-invalid={!!errors.nationality_code}>
                                <div className="flex items-center justify-between gap-2">
                                    <FieldLabel htmlFor="check-in-nationality_code">
                                        Nationality
                                    </FieldLabel>
                                    <MyKadScanner
                                        onScan={(card) =>
                                            form.setData(
                                                'nationality_code',
                                                card.nationality_code || 'MY',
                                            )
                                        }
                                    />
                                </div>
                                <CountrySelect
                                    id="check-in-nationality_code"
                                    name="nationality_code"
                                    value={data.nationality_code}
                                    onValueChange={(value) =>
                                        form.setData('nationality_code', value)
                                    }
                                    options={preview.countries}
                                    invalid={!!errors.nationality_code}
                                />
                                <FieldDescription>
                                    A tax depends on it. It is saved to the
                                    guest profile.
                                </FieldDescription>
                                <FieldError>
                                    {errors.nationality_code}
                                </FieldError>
                            </Field>
                        )}
                        {preview.creditExceeded &&
                            (preview.canOverrideCredit ? (
                                <Field
                                    data-invalid={
                                        !!errors.credit_override_reason
                                    }
                                >
                                    <FieldLabel htmlFor="check-in-credit_override_reason">
                                        Credit limit override reason
                                    </FieldLabel>
                                    <Textarea
                                        id="check-in-credit_override_reason"
                                        value={data.credit_override_reason}
                                        onChange={(event) =>
                                            form.setData(
                                                'credit_override_reason',
                                                event.target.value,
                                            )
                                        }
                                        maxLength={500}
                                        rows={2}
                                        aria-invalid={
                                            !!errors.credit_override_reason
                                        }
                                    />
                                    <FieldDescription>
                                        This company is over its credit limit. A
                                        reason lets you check in anyway.
                                    </FieldDescription>
                                    <FieldError>
                                        {errors.credit_override_reason}
                                    </FieldError>
                                </Field>
                            ) : (
                                <p className="text-sm text-destructive">
                                    This company is over its credit limit. Ask
                                    someone who may override it.
                                </p>
                            ))}
                        {collection !== null && (
                            <div className="flex flex-col gap-2 rounded-lg border p-3 text-sm">
                                <span className="font-medium">To collect</span>
                                {taxRowsShown &&
                                    collection.taxes.map((tax) => (
                                        <TaxRow
                                            key={tax.taxId}
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
                                            onWaiveTax={onWaiveTax}
                                        />
                                    ))}
                                {collection.deposit !== null && (
                                    <CollectRow
                                        id="check-in-collect_deposit"
                                        label="Security deposit"
                                        amount={collection.deposit}
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
                                        idPrefix="check-in"
                                        charges={charges}
                                        owed={chargesOwed}
                                        paid={chargesPaidShown}
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
                                            id="check-in-collect_now"
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
                                    <FieldLabel htmlFor="check-in-payment_method_id">
                                        Method
                                    </FieldLabel>
                                    {paymentOptions === undefined ? (
                                        <Skeleton className="h-9 w-full" />
                                    ) : (
                                        <EnumSelect
                                            id="check-in-payment_method_id"
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
                                        <FieldLabel htmlFor="check-in-reference">
                                            Reference
                                        </FieldLabel>
                                        <Input
                                            id="check-in-reference"
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
 * Check in one unit. Shows the room and the registration card status, a Nationality field (with the MyKad reader) when
 * a tax needs it, the overrides the user may use, and for a user who may record payments one To collect list: the
 * taxes flagged for check-in, each with a tick (Exempt, who paid a waived one, or where a Master tax is charged), the
 * security deposit, the room folio's charges as a group with a tick each, and Collect now with the total. The form sends choices only. The server works out every amount.
 */
export function CheckInDialog(props: Props) {
    return <CheckInForm key={useFreshOnOpen(props.open)} {...props} />;
}
