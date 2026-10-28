import { useForm } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { BookingQuoteCell } from '@/components/pms/booking-quote-cell';
import { DatePicker } from '@/components/pms/date-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { BookingUnitPicker } from '@/components/pms/reservations/booking-unit-picker';
import {
    emptyLine,
    plansFor,
} from '@/components/pms/reservations/booking-lines';
import {
    dayUsePlanOptions,
    dayUseRoomTypes,
    endsAt,
    enteredEndsAt,
    isDayUseQuotable,
    isEnteredPlan,
    isHalfHour,
    offerOptions,
    planIdFromSelect,
    planSelectValue,
    timeText,
} from '@/components/pms/reservations/day-use';
import { useDayUseOffers } from '@/components/pms/reservations/use-day-use-offers';
import { useEnteredSwitch } from '@/components/pms/reservations/use-entered-switch';
import { useBookingQuote } from '@/components/pms/reservations/use-booking-quote';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store } from '@/routes/reservations/units';

type Options = App.Data.Reservations.AddUnitOptionsData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    /** The reservation's company: a plan linked to companies shows only for one of them. */
    companyId: number | null;
    options?: Options;
    currency: App.Data.Property.CurrencyData | null;
    /** A reservation is all overnight or all day use: a day use reservation adds day use lines. */
    stayType?: 'overnight' | 'day_use';
};

const EMPTY = {
    room_type_id: '',
    rate_plan_id: '',
    arrival_date: '',
    departure_date: '',
    adults: '2',
    child_ages: '',
    override_total: '',
    override_reason: '',
    restriction_override_reason: '',
    stay_type: 'overnight',
    start_time: '10:00',
    hours: '',
    room_id: '',
};

/** "4, 9" becomes [4, 9]. A bad entry stays a string, so the server refuses it and the field shows the error. */
function parseAges(text: string): (number | string)[] {
    return text
        .split(',')
        .map((part) => part.trim())
        .filter((part) => part !== '')
        .map((part) => (/^\d+$/.test(part) ? Number(part) : part));
}

function AddUnitForm({
    open,
    onOpenChange,
    reservationId,
    companyId,
    options,
    currency,
    stayType = 'overnight',
}: Props) {
    useFormOptions(open, options !== undefined, 'addUnitOptions');

    const form = useForm(EMPTY);
    const { data, errors } = form;
    const [overrideOpen, setOverrideOpen] = useState(false);
    const [restrictionAsked, setRestrictionAsked] = useState(false);

    const submit = () => {
        form.transform((values) =>
            isDayUse
                ? {
                      ...values,
                      stay_type: 'day_use',
                      departure_date: values.arrival_date,
                      child_ages: parseAges(values.child_ages),
                  }
                : {
                      ...values,
                      child_ages: parseAges(values.child_ages),
                      start_time: '',
                      hours: '',
                      room_id: '',
                  },
        );
        form.post(store.url({ reservation: reservationId }), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
            onError: (formErrors) => {
                if (formErrors.restriction_override_reason) {
                    setRestrictionAsked(true);
                }
            },
        });
    };

    const isDayUse = stayType === 'day_use';
    const quoteLine = {
        ...emptyLine(),
        ...data,
        child_ages: parseAges(data.child_ages).map(String),
    };
    const { quote, loading: quoteLoading } = useBookingQuote(
        quoteLine,
        data.arrival_date,
        data.arrival_date,
        false,
        isDayUse &&
            isDayUseQuotable(quoteLine, data.arrival_date, data.start_time),
        data.start_time,
    );
    const roomTypes = isDayUse
        ? dayUseRoomTypes(options?.roomTypes ?? [])
        : (options?.roomTypes ?? []);
    const ratePlans = plansFor(
        options?.ratePlans ?? [],
        data.room_type_id,
        companyId === null ? '' : String(companyId),
        isDayUse,
    );
    const roomType = roomTypes.find(
        (type) => String(type.id) === data.room_type_id,
    );
    const entered = isDayUse && isEnteredPlan(data.rate_plan_id, roomType);
    const { offers, loaded } = useDayUseOffers(
        isDayUse ? data.room_type_id : '',
        entered ? '' : data.rate_plan_id,
        data.arrival_date,
        isHalfHour(data.start_time) ? data.start_time : '',
    );
    const planName =
        !isDayUse || entered || data.rate_plan_id === ''
            ? null
            : (ratePlans.find((plan) => String(plan.id) === data.rate_plan_id)
                  ?.name ?? null);
    const { note, clear } = useEnteredSwitch({
        roomType,
        planName,
        offers,
        loaded,
        hours: data.hours,
        onSwitch: (ratePlanId) =>
            form.setData((current) => ({
                ...current,
                rate_plan_id: ratePlanId,
            })),
        onUnsold: () =>
            form.setData((current) => ({ ...current, hours: '', room_id: '' })),
    });
    const money = (amount: string) =>
        new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency?.code ?? 'MYR',
        }).format(Number(amount));
    const ends = entered
        ? enteredEndsAt(data.start_time, data.hours)
        : endsAt(offers, data.hours);
    const overrideInvalid = !!errors.override_total || !!errors.override_reason;
    const childAgesError = Object.entries(errors).find(([key]) =>
        key.startsWith('child_ages'),
    )?.[1];

    return (
        <FormOverlay
            variant="sheet"
            size="lg"
            open={open}
            onOpenChange={onOpenChange}
            title="Add unit"
            description={
                isDayUse
                    ? 'One room for a number of hours on one day. Add more units one at a time.'
                    : 'One room, or one bed in a dorm. Add more units one at a time.'
            }
            errors={errors}
            dirty={form.isDirty}
            discardTitle="Discard this unit?"
            onSubmit={submit}
            submitLabel="Add unit"
            processing={form.processing}
            loading={!options}
        >
            <Field data-invalid={!!errors.room_type_id}>
                <FieldLabel htmlFor="unit-room_type_id">Room type</FieldLabel>
                <EnumSelect
                    id="unit-room_type_id"
                    options={roomTypes.map((type) => ({
                        value: String(type.id),
                        label: type.name,
                    }))}
                    value={data.room_type_id}
                    onValueChange={(value) => {
                        form.setData((current) => ({
                            ...current,
                            room_type_id: value,
                            rate_plan_id: '',
                            hours: '',
                            room_id: '',
                        }));
                    }}
                    aria-invalid={!!errors.room_type_id}
                />
                <FieldError>{errors.room_type_id}</FieldError>
            </Field>
            <Field data-invalid={!!errors.rate_plan_id}>
                <FieldLabel htmlFor="unit-rate_plan_id">Rate plan</FieldLabel>
                <EnumSelect
                    id="unit-rate_plan_id"
                    options={
                        isDayUse
                            ? dayUsePlanOptions(ratePlans, roomType)
                            : ratePlans.map((plan) => ({
                                  value: String(plan.id),
                                  label: plan.name,
                              }))
                    }
                    value={
                        isDayUse
                            ? planSelectValue(data.rate_plan_id, roomType)
                            : data.rate_plan_id
                    }
                    onValueChange={(value) => {
                        clear();
                        form.setData((current) => ({
                            ...current,
                            rate_plan_id: isDayUse
                                ? planIdFromSelect(value, roomType)
                                : value,
                            hours: '',
                            room_id: '',
                        }));
                    }}
                    disabled={data.room_type_id === ''}
                    aria-invalid={!!errors.rate_plan_id}
                />
                {note && entered && <FieldDescription>{note}</FieldDescription>}
                <FieldError>{errors.rate_plan_id}</FieldError>
            </Field>
            {isDayUse ? (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field data-invalid={!!errors.arrival_date}>
                            <FieldLabel htmlFor="unit-arrival_date">
                                Date
                            </FieldLabel>
                            <DatePicker
                                id="unit-arrival_date"
                                value={data.arrival_date}
                                onChange={(value) =>
                                    form.setData((current) => ({
                                        ...current,
                                        arrival_date: value,
                                        hours: '',
                                        room_id: '',
                                    }))
                                }
                                aria-invalid={!!errors.arrival_date}
                            />
                            <FieldError>{errors.arrival_date}</FieldError>
                        </Field>
                        <Field data-invalid={!!errors.start_time}>
                            <FieldLabel htmlFor="unit-start_time">
                                Start
                            </FieldLabel>
                            <Input
                                id="unit-start_time"
                                type="time"
                                step={1800}
                                value={data.start_time}
                                onChange={(event) =>
                                    form.setData((current) => ({
                                        ...current,
                                        start_time: event.target.value,
                                        hours: '',
                                        room_id: '',
                                    }))
                                }
                                aria-invalid={!!errors.start_time}
                            />
                            <FieldDescription>
                                On the hour or the half hour.
                            </FieldDescription>
                            <FieldError>{errors.start_time}</FieldError>
                        </Field>
                    </div>
                    <Field data-invalid={!!errors.hours}>
                        <FieldLabel htmlFor="unit-hours">Hours</FieldLabel>
                        {entered ? (
                            <Input
                                id="unit-hours"
                                type="number"
                                min={1}
                                max={23}
                                step={1}
                                placeholder="e.g. 5"
                                className="max-w-32"
                                value={data.hours}
                                onChange={(event) =>
                                    form.setData((current) => ({
                                        ...current,
                                        hours: event.target.value,
                                        room_id: '',
                                    }))
                                }
                                aria-invalid={!!errors.hours}
                            />
                        ) : (
                            <EnumSelect
                                id="unit-hours"
                                placeholder={
                                    loaded && offers.length === 0
                                        ? 'No hours sold'
                                        : 'Select hours'
                                }
                                options={offerOptions(offers, money)}
                                value={data.hours}
                                onValueChange={(value) =>
                                    form.setData((current) => ({
                                        ...current,
                                        hours: value,
                                        room_id: '',
                                    }))
                                }
                                disabled={offers.length === 0}
                                aria-invalid={!!errors.hours}
                            />
                        )}
                        {ends && (
                            <FieldDescription>
                                Ends {timeText(ends)}.
                            </FieldDescription>
                        )}
                        <FieldError>{errors.hours}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.room_id}>
                        <FieldLabel htmlFor="unit-room_id">Room</FieldLabel>
                        <BookingUnitPicker
                            id="unit-room_id"
                            label="Room"
                            roomTypeId={data.room_type_id}
                            arrival={data.arrival_date}
                            departure={data.arrival_date}
                            dayUse={{
                                start: data.start_time,
                                hours: data.hours,
                            }}
                            value={data.room_id}
                            onChange={(value) => form.setData('room_id', value)}
                            emptyLabel="Select room"
                            takenIds={new Set()}
                            invalid={!!errors.room_id}
                        />
                        <FieldDescription>
                            Rooms free for those hours.
                        </FieldDescription>
                        <FieldError>{errors.room_id}</FieldError>
                    </Field>
                </>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!errors.arrival_date}>
                        <FieldLabel htmlFor="unit-arrival_date">
                            Arrival
                        </FieldLabel>
                        <DatePicker
                            id="unit-arrival_date"
                            value={data.arrival_date}
                            onChange={(value) =>
                                form.setData('arrival_date', value)
                            }
                            aria-invalid={!!errors.arrival_date}
                        />
                        <FieldError>{errors.arrival_date}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.departure_date}>
                        <FieldLabel htmlFor="unit-departure_date">
                            Departure
                        </FieldLabel>
                        <DatePicker
                            id="unit-departure_date"
                            value={data.departure_date}
                            min={data.arrival_date || undefined}
                            onChange={(value) =>
                                form.setData('departure_date', value)
                            }
                            aria-invalid={!!errors.departure_date}
                        />
                        <FieldError>{errors.departure_date}</FieldError>
                    </Field>
                </div>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
                <Field data-invalid={!!errors.adults}>
                    <FieldLabel htmlFor="unit-adults">Adults</FieldLabel>
                    <Input
                        id="unit-adults"
                        type="number"
                        min={1}
                        max={99}
                        value={data.adults}
                        onChange={(event) =>
                            form.setData('adults', event.target.value)
                        }
                        aria-invalid={!!errors.adults}
                    />
                    {roomType && (
                        <FieldDescription>
                            Up to {roomType.maxAdults} for this room type.
                        </FieldDescription>
                    )}
                    <FieldError>{errors.adults}</FieldError>
                </Field>
                <Field data-invalid={!!childAgesError}>
                    <FieldLabel htmlFor="unit-child_ages">
                        Child ages
                    </FieldLabel>
                    <Input
                        id="unit-child_ages"
                        value={data.child_ages}
                        onChange={(event) =>
                            form.setData('child_ages', event.target.value)
                        }
                        placeholder="4, 9"
                        autoComplete="off"
                        aria-invalid={!!childAgesError}
                    />
                    <FieldDescription>
                        Separate ages with commas.
                    </FieldDescription>
                    <FieldError>{childAgesError}</FieldError>
                </Field>
            </div>
            {isDayUse && (
                <BookingQuoteCell
                    quote={quote}
                    loading={quoteLoading}
                    currency={currency}
                    overrideTotal={data.override_total}
                    overrideReason={data.override_reason}
                    onOverrideChange={(patch) =>
                        form.setData((current) => ({ ...current, ...patch }))
                    }
                    errors={Object.fromEntries(
                        Object.entries(errors).map(([key, value]) => [
                            `lines.0.${key}`,
                            value,
                        ]),
                    )}
                    index={0}
                    enteredPrice={entered}
                />
            )}
            {!isDayUse && (
                <Collapsible
                    open={overrideOpen || overrideInvalid}
                    onOpenChange={setOverrideOpen}
                    className="flex flex-col gap-4"
                >
                    <CollapsibleTrigger
                        render={
                            <Button
                                type="button"
                                variant="ghost"
                                className="justify-between"
                            />
                        }
                    >
                        Price override
                        <ChevronDown data-icon="inline-end" />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="flex flex-col gap-4">
                        <Field data-invalid={!!errors.override_total}>
                            <FieldLabel htmlFor="unit-override_total">
                                Stay total
                            </FieldLabel>
                            <MoneyInput
                                id="unit-override_total"
                                value={data.override_total}
                                onChange={(value) =>
                                    form.setData('override_total', value)
                                }
                                currency={currency}
                                invalid={!!errors.override_total}
                            />
                            <FieldDescription>
                                Replaces the quoted price for this unit. Leave
                                empty to use the rate.
                            </FieldDescription>
                            <FieldError>{errors.override_total}</FieldError>
                        </Field>
                        <Field data-invalid={!!errors.override_reason}>
                            <FieldLabel htmlFor="unit-override_reason">
                                Reason (optional)
                            </FieldLabel>
                            <Input
                                id="unit-override_reason"
                                value={data.override_reason}
                                onChange={(event) =>
                                    form.setData(
                                        'override_reason',
                                        event.target.value,
                                    )
                                }
                                maxLength={500}
                                placeholder="e.g. Corporate rate"
                                aria-invalid={!!errors.override_reason}
                            />
                            <FieldError>{errors.override_reason}</FieldError>
                        </Field>
                    </CollapsibleContent>
                </Collapsible>
            )}
            {restrictionAsked && (
                <Field data-invalid={!!errors.restriction_override_reason}>
                    <FieldLabel htmlFor="unit-restriction_override_reason">
                        Restriction override reason
                    </FieldLabel>
                    <Input
                        id="unit-restriction_override_reason"
                        value={data.restriction_override_reason}
                        onChange={(event) =>
                            form.setData(
                                'restriction_override_reason',
                                event.target.value,
                            )
                        }
                        maxLength={500}
                        aria-invalid={!!errors.restriction_override_reason}
                    />
                    <FieldError>
                        {errors.restriction_override_reason}
                    </FieldError>
                </Field>
            )}
        </FormOverlay>
    );
}

/**
 * Adds one booking line to the reservation. The room is not picked here: it is assigned when the guest arrives.
 */
export function AddUnitSheet(props: Props) {
    return <AddUnitForm key={useFreshOnOpen(props.open)} {...props} />;
}
