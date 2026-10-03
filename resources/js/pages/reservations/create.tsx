import { Head, Link, useForm } from '@inertiajs/react';
import {
    BedDouble,
    CalendarPlus,
    NotebookText,
    Package,
    Percent,
    Plus,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormActions } from '@/components/pms/form-actions';
import { PageHeader } from '@/components/pms/page-header';
import {
    BookingAddOnsSection,
    BookingDiscountsSection,
} from '@/components/pms/reservations/booking-extras-section';
import { BookingGuestsSection } from '@/components/pms/reservations/booking-guests-section';
import {
    clampStaysIn,
    emptyGuest,
    guestLabel,
} from '@/components/pms/reservations/booking-guests';
import {
    BookingLineRow,
    BookingLinesHeader,
} from '@/components/pms/reservations/booking-line-row';
import {
    addDays,
    bookingPayload,
    emptyLine,
    nightsBetween,
    totalUnits,
    unitOptions,
} from '@/components/pms/reservations/booking-lines';
import type {
    BookingForm,
    BookingLine,
} from '@/components/pms/reservations/booking-lines';
import { BookingSummaryRail } from '@/components/pms/reservations/booking-summary-rail';
import {
    errorTabs,
    firstErrorTab,
    tabClass,
} from '@/components/pms/reservations/booking-tabs';
import type { BookingTab } from '@/components/pms/reservations/booking-tabs';
import { FormError } from '@/components/pms/reservations/form-error';
import { StayDateTime } from '@/components/pms/reservations/stay-date-time';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupText,
    InputGroupTextarea,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { index, newMethod, store, walkIn } from '@/routes/reservations';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';

/** How far ahead an arrival can be picked, and the longest stay the departure menu offers. */
const BOOKING_WINDOW_DAYS = 1095;
const LONGEST_STAY_DAYS = 365;

/** The longest note the server takes. */
const NOTES_LIMIT = 2000;

type Quote = App.Data.Reservations.BookingQuoteData;

type Props = {
    isWalkIn: boolean;
    sources: EnumOption[];
    /** The business date, `Y-m-d`. Not named `businessDate`: that is the shared prop the header indicator reads. */
    today: string;
    /** The property's check-in and check-out times, `H:i`: the defaults of the expected times. */
    checkInTime: string;
    checkOutTime: string;
    /** The current time at the property, `H:i`: the walk-in arrival time. */
    nowTime: string;
    addUnitOptions: App.Data.Reservations.AddUnitOptionsData;
    chargeItems: App.Data.Reservations.ChargeItemOptionData[];
    discounts: App.Data.Reservations.DiscountOptionData[];
    billingModes: EnumOption[];
    autoAssignRooms: boolean;
    currency: App.Enums.Currency | null;
    canOverrideRestrictions: boolean;
    canManageGuests: boolean;
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
};

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h2 className="font-heading text-base font-semibold">
                    {title}
                </h2>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

export default function ReservationCreate({
    isWalkIn,
    sources,
    today,
    checkInTime,
    checkOutTime,
    nowTime,
    addUnitOptions,
    chargeItems,
    discounts,
    billingModes,
    autoAssignRooms,
    currency,
    canOverrideRestrictions,
    canManageGuests,
    countries,
    genders,
    locales,
}: Props) {
    const form = useForm<BookingForm>({
        source: sources[0]?.value ?? 'direct',
        external_reference: '',
        arrival_date: today,
        departure_date: '',
        arrival_time: isWalkIn ? nowTime : checkInTime,
        departure_time: checkOutTime,
        group_name: '',
        billing_mode: billingModes[0]?.value ?? 'master_pays',
        notes: '',
        guests: [emptyGuest()],
        lines: [emptyLine()],
    });
    const { data, errors } = form;
    const [quotes, setQuotes] = useState<Record<string, Quote>>({});
    const [tab, setTab] = useState<BookingTab>('rooms');
    const guard = useUnsavedChanges(form.isDirty && !form.processing);
    const mode = isWalkIn ? 'walk-in' : 'new';
    const lastMode = useRef(isWalkIn);

    // A mode switch keeps the page mounted, so the form follows the new mode when the props change.
    useEffect(() => {
        if (lastMode.current === isWalkIn) {
            return;
        }

        lastMode.current = isWalkIn;
        form.setData((current) => ({
            ...current,
            source: isWalkIn ? 'walk_in' : (sources[0]?.value ?? 'direct'),
            arrival_date: isWalkIn ? today : current.arrival_date,
            arrival_time: isWalkIn ? nowTime : current.arrival_time,
        }));
    }, [isWalkIn]);

    const switchMode = (next: string) => {
        if (next === mode) {
            return;
        }

        guard.visitWithout((next === 'walk-in' ? walkIn : newMethod).url(), {
            preserveState: true,
            preserveScroll: true,
            only: ['isWalkIn', 'sources', 'nowTime'],
        });
    };
    const roomTypes = addUnitOptions.roomTypes;
    const units = totalUnits(data.lines, roomTypes);
    const unitChoices = unitOptions(data.lines, roomTypes);

    useEffect(() => {
        const next = clampStaysIn(form.data.guests, unitChoices.length);

        if (next !== form.data.guests) {
            form.setData('guests', next);
        }
    }, [unitChoices.length]);
    const lastBookable = addDays(today, BOOKING_WINDOW_DAYS);
    const nights = nightsBetween(data.arrival_date, data.departure_date);
    const lockedNights = useMemo(() => {
        for (const line of data.lines) {
            const plan = addUnitOptions.ratePlans.find(
                (one) => String(one.id) === line.rate_plan_id,
            );

            if (plan?.fixedNights) {
                return plan.fixedNights;
            }
        }

        return null;
    }, [data.lines, addUnitOptions.ratePlans]);
    const departure =
        lockedNights !== null && data.arrival_date
            ? addDays(data.arrival_date, lockedNights)
            : data.departure_date;
    const emptyPickLabel = isWalkIn
        ? 'Select'
        : autoAssignRooms
          ? 'Auto-assign'
          : 'Select later';

    const setLine = (position: number, patch: Partial<BookingLine>) =>
        form.setData(
            'lines',
            data.lines.map((line, at) =>
                at === position ? { ...line, ...patch } : line,
            ),
        );
    const onQuote = useCallback((key: string, quote: Quote | null) => {
        setQuotes((current) => {
            if (quote === null) {
                if (!(key in current)) {
                    return current;
                }

                const { [key]: _removed, ...rest } = current;

                return rest;
            }

            return current[key] === quote
                ? current
                : { ...current, [key]: quote };
        });
    }, []);

    /** The rooms and beds picked on the other lines, so the picker shows them as taken. */
    const takenBy = (position: number) => {
        const taken = new Set<string>();

        data.lines.forEach((line, at) => {
            if (at === position) {
                return;
            }

            if (line.room_id) {
                taken.add(line.room_id);
            }

            line.bed_ids.filter(Boolean).forEach((id) => taken.add(id));
        });

        return taken;
    };

    const warnings = data.lines.flatMap(
        (line) => quotes[line.key]?.violations ?? [],
    );
    const askOverride =
        canOverrideRestrictions &&
        (warnings.length > 0 ||
            data.lines.some(
                (_, i) => errors[`lines.${i}.restriction_override_reason`],
            ));

    const tabErrors = errorTabs(errors);
    const addOnCount = data.lines.reduce(
        (sum, line) =>
            sum +
            line.add_ons.filter((addOn) => addOn.charge_item_id !== '').length,
        0,
    );

    const submit = (event?: React.FormEvent) => {
        event?.preventDefault();
        form.transform(
            (values) =>
                bookingPayload(
                    { ...values, departure_date: departure },
                    roomTypes,
                ) as unknown as BookingForm,
        );
        form.post(store.url(), {
            preserveScroll: true,
            onError: (submitted) => {
                const failing = firstErrorTab(errorTabs(submitted));

                if (failing !== null) {
                    setTab(failing);
                }

                document
                    .querySelector('[aria-invalid="true"]')
                    ?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            },
        });
    };

    return (
        <>
            <Head title={isWalkIn ? 'Walk-in' : 'New booking'} />

            <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
                <div
                    scroll-region=""
                    className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 pb-12"
                >
                    <PageHeader
                        title={isWalkIn ? 'Walk-in' : 'New booking'}
                        description={
                            isWalkIn
                                ? 'A guest at the desk now. Every unit needs a room or bed.'
                                : 'Reserve rooms or beds for a guest who arrives on a later date.'
                        }
                        back={{ href: index.url(), label: 'Reservations' }}
                    />

                    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                        <div className="flex flex-col gap-8">
                            <FormError errors={errors} />

                            <Section
                                title="Stay"
                                description="The dates and expected times apply to every line."
                            >
                                <FieldGroup>
                                    <div className="grid items-start gap-4 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_12rem]">
                                        <Field
                                            data-invalid={
                                                !!errors.arrival_date ||
                                                !!errors.arrival_time
                                            }
                                        >
                                            <FieldLabel htmlFor="booking-arrival">
                                                Arrival
                                            </FieldLabel>
                                            <StayDateTime
                                                id="booking-arrival"
                                                date={data.arrival_date}
                                                time={data.arrival_time}
                                                timeLabel="Arrival time"
                                                dateLocked={isWalkIn}
                                                min={today}
                                                max={lastBookable}
                                                onDateChange={(value) =>
                                                    form.setData(
                                                        'arrival_date',
                                                        value,
                                                    )
                                                }
                                                onTimeChange={(value) =>
                                                    form.setData(
                                                        'arrival_time',
                                                        value,
                                                    )
                                                }
                                                aria-invalid={
                                                    !!errors.arrival_date
                                                }
                                                timeInvalid={
                                                    !!errors.arrival_time
                                                }
                                            />
                                            <FieldError>
                                                {errors.arrival_date ??
                                                    errors.arrival_time}
                                            </FieldError>
                                        </Field>
                                        <Field>
                                            <FieldLabel
                                                aria-hidden
                                                className="invisible max-sm:hidden"
                                            >
                                                Nights
                                            </FieldLabel>
                                            <div
                                                className="flex h-8 min-w-16 flex-col items-center justify-center rounded-lg bg-muted px-3 text-xs leading-tight tabular-nums max-sm:justify-self-start"
                                                aria-label="Nights"
                                            >
                                                <span className="font-medium">
                                                    {lockedNights !== null
                                                        ? lockedNights
                                                        : nights}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {(lockedNights ??
                                                        nights) === 1
                                                        ? 'Night'
                                                        : 'Nights'}
                                                </span>
                                            </div>
                                        </Field>
                                        <Field
                                            data-invalid={
                                                !!errors.departure_date ||
                                                !!errors.departure_time
                                            }
                                        >
                                            <FieldLabel htmlFor="booking-departure">
                                                Departure
                                            </FieldLabel>
                                            <StayDateTime
                                                id="booking-departure"
                                                date={
                                                    lockedNights !== null
                                                        ? data.arrival_date
                                                            ? departure
                                                            : ''
                                                        : data.departure_date
                                                }
                                                time={data.departure_time}
                                                timeLabel="Departure time"
                                                dateLocked={
                                                    lockedNights !== null
                                                }
                                                min={
                                                    data.arrival_date
                                                        ? addDays(
                                                              data.arrival_date,
                                                              1,
                                                          )
                                                        : addDays(today, 1)
                                                }
                                                max={addDays(
                                                    data.arrival_date || today,
                                                    LONGEST_STAY_DAYS,
                                                )}
                                                onDateChange={(value) =>
                                                    form.setData(
                                                        'departure_date',
                                                        value,
                                                    )
                                                }
                                                onTimeChange={(value) =>
                                                    form.setData(
                                                        'departure_time',
                                                        value,
                                                    )
                                                }
                                                aria-invalid={
                                                    !!errors.departure_date
                                                }
                                                timeInvalid={
                                                    !!errors.departure_time
                                                }
                                            />
                                            {lockedNights !== null && (
                                                <FieldDescription>
                                                    Date set by the package.
                                                </FieldDescription>
                                            )}
                                            <FieldError>
                                                {errors.departure_date ??
                                                    errors.departure_time}
                                            </FieldError>
                                        </Field>
                                        <Field>
                                            <FieldLabel htmlFor="booking-mode">
                                                Reservation mode
                                            </FieldLabel>
                                            <EnumSelect
                                                id="booking-mode"
                                                options={[
                                                    {
                                                        value: 'new',
                                                        label: 'New booking',
                                                    },
                                                    {
                                                        value: 'walk-in',
                                                        label: 'Walk-in',
                                                    },
                                                ]}
                                                value={mode}
                                                onValueChange={switchMode}
                                            />
                                        </Field>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        <Field data-invalid={!!errors.source}>
                                            <FieldLabel htmlFor="booking-source">
                                                Source
                                            </FieldLabel>
                                            <EnumSelect
                                                id="booking-source"
                                                options={sources}
                                                value={data.source}
                                                onValueChange={(value) =>
                                                    form.setData(
                                                        'source',
                                                        value,
                                                    )
                                                }
                                                disabled={sources.length === 1}
                                                aria-invalid={!!errors.source}
                                            />
                                            <FieldError>
                                                {errors.source}
                                            </FieldError>
                                        </Field>
                                        <Field
                                            data-invalid={
                                                !!errors.external_reference
                                            }
                                        >
                                            <FieldLabel htmlFor="booking-external_reference">
                                                External reference
                                            </FieldLabel>
                                            <Input
                                                id="booking-external_reference"
                                                value={data.external_reference}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'external_reference',
                                                        event.target.value,
                                                    )
                                                }
                                                maxLength={100}
                                                placeholder="e.g. the agent's booking number"
                                                autoComplete="off"
                                                aria-invalid={
                                                    !!errors.external_reference
                                                }
                                            />
                                            <FieldError>
                                                {errors.external_reference}
                                            </FieldError>
                                        </Field>
                                        {units > 1 && (
                                            <Field
                                                data-invalid={
                                                    !!errors.group_name
                                                }
                                            >
                                                <FieldLabel htmlFor="booking-group_name">
                                                    Group name
                                                </FieldLabel>
                                                <Input
                                                    id="booking-group_name"
                                                    value={data.group_name}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'group_name',
                                                            event.target.value,
                                                        )
                                                    }
                                                    maxLength={150}
                                                    placeholder="e.g. Tan family trip"
                                                    autoComplete="off"
                                                    aria-invalid={
                                                        !!errors.group_name
                                                    }
                                                />
                                                <FieldError>
                                                    {errors.group_name}
                                                </FieldError>
                                            </Field>
                                        )}
                                    </div>
                                </FieldGroup>
                            </Section>

                            <Tabs
                                value={tab}
                                onValueChange={(value) =>
                                    setTab(value as BookingTab)
                                }
                            >
                                <TabsList
                                    variant="line"
                                    className="h-auto w-full justify-start border-b"
                                >
                                    <TabsTrigger
                                        value="rooms"
                                        className={tabClass(tabErrors.rooms)}
                                    >
                                        <BedDouble data-icon="inline-start" />
                                        Rooms &amp; guests
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="discounts"
                                        className={tabClass(
                                            tabErrors.discounts,
                                        )}
                                    >
                                        <Percent data-icon="inline-start" />
                                        Discounts
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="add-ons"
                                        className={tabClass(
                                            tabErrors['add-ons'],
                                        )}
                                    >
                                        <Package data-icon="inline-start" />
                                        Add-ons
                                        {addOnCount > 0 && (
                                            <Badge variant="secondary">
                                                {addOnCount}
                                            </Badge>
                                        )}
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="notes"
                                        className={tabClass(tabErrors.notes)}
                                    >
                                        <NotebookText data-icon="inline-start" />
                                        Notes
                                    </TabsTrigger>
                                </TabsList>

                                <TabsContent
                                    value="rooms"
                                    keepMounted
                                    className="flex flex-col gap-8 pt-4"
                                >
                                    <Section
                                        title="Rooms and beds"
                                        description="One line for each room type and rate plan. A dorm line sells beds."
                                    >
                                        <div className="flex flex-col gap-3">
                                            <div className="overflow-hidden rounded-lg border">
                                                <BookingLinesHeader />
                                                <div className="divide-y">
                                                    {data.lines.map(
                                                        (line, position) => (
                                                            <BookingLineRow
                                                                key={line.key}
                                                                index={position}
                                                                line={line}
                                                                options={
                                                                    addUnitOptions
                                                                }
                                                                arrival={
                                                                    data.arrival_date
                                                                }
                                                                departure={
                                                                    departure
                                                                }
                                                                currency={
                                                                    currency
                                                                }
                                                                errors={errors}
                                                                emptyPickLabel={
                                                                    emptyPickLabel
                                                                }
                                                                takenIds={takenBy(
                                                                    position,
                                                                )}
                                                                canRemove={
                                                                    data.lines
                                                                        .length >
                                                                    1
                                                                }
                                                                onChange={(
                                                                    patch,
                                                                ) =>
                                                                    setLine(
                                                                        position,
                                                                        patch,
                                                                    )
                                                                }
                                                                onRemove={() =>
                                                                    form.setData(
                                                                        'lines',
                                                                        data.lines.filter(
                                                                            (
                                                                                _,
                                                                                at,
                                                                            ) =>
                                                                                at !==
                                                                                position,
                                                                        ),
                                                                    )
                                                                }
                                                                onQuote={
                                                                    onQuote
                                                                }
                                                            />
                                                        ),
                                                    )}
                                                </div>
                                                <div className="flex justify-end border-t bg-muted/50 px-3 py-2">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            form.setData(
                                                                'lines',
                                                                [
                                                                    ...data.lines,
                                                                    emptyLine(),
                                                                ],
                                                            )
                                                        }
                                                    >
                                                        <Plus data-icon="inline-start" />
                                                        Add line
                                                    </Button>
                                                </div>
                                            </div>
                                            {warnings.length > 0 && (
                                                <Alert>
                                                    <AlertDescription>
                                                        <ul className="flex flex-col gap-1">
                                                            {warnings.map(
                                                                (warning) => (
                                                                    <li
                                                                        key={
                                                                            warning
                                                                        }
                                                                    >
                                                                        {
                                                                            warning
                                                                        }
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ul>
                                                        {canOverrideRestrictions
                                                            ? 'You can book this anyway. Enter a reason for each line below.'
                                                            : 'Ask someone who may book past restrictions.'}
                                                    </AlertDescription>
                                                </Alert>
                                            )}
                                            {askOverride &&
                                                data.lines.map(
                                                    (line, position) =>
                                                        (quotes[line.key]
                                                            ?.violations
                                                            .length ?? 0) > 0 ||
                                                        errors[
                                                            `lines.${position}.restriction_override_reason`
                                                        ] ? (
                                                            <Field
                                                                key={line.key}
                                                                data-invalid={
                                                                    !!errors[
                                                                        `lines.${position}.restriction_override_reason`
                                                                    ]
                                                                }
                                                            >
                                                                <FieldLabel
                                                                    htmlFor={`line-${position}-restriction`}
                                                                >
                                                                    Restriction
                                                                    override
                                                                    reason, line{' '}
                                                                    {position +
                                                                        1}
                                                                </FieldLabel>
                                                                <Input
                                                                    id={`line-${position}-restriction`}
                                                                    value={
                                                                        line.restriction_override_reason
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        setLine(
                                                                            position,
                                                                            {
                                                                                restriction_override_reason:
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                    maxLength={
                                                                        500
                                                                    }
                                                                    aria-invalid={
                                                                        !!errors[
                                                                            `lines.${position}.restriction_override_reason`
                                                                        ]
                                                                    }
                                                                />
                                                                <FieldError>
                                                                    {
                                                                        errors[
                                                                            `lines.${position}.restriction_override_reason`
                                                                        ]
                                                                    }
                                                                </FieldError>
                                                            </Field>
                                                        ) : null,
                                                )}
                                        </div>
                                    </Section>

                                    <Section
                                        title="Guests"
                                        description="The booker, and the guest of each unit. Units without a guest can be filled from the reservation page."
                                    >
                                        <BookingGuestsSection
                                            guests={data.guests}
                                            units={unitChoices}
                                            canManageGuests={canManageGuests}
                                            countries={countries}
                                            genders={genders}
                                            locales={locales}
                                            errors={errors}
                                            onChange={(guests) =>
                                                form.setData('guests', guests)
                                            }
                                        />
                                    </Section>
                                </TabsContent>

                                <TabsContent
                                    value="discounts"
                                    keepMounted
                                    className="pt-4"
                                >
                                    <BookingDiscountsSection
                                        lines={data.lines}
                                        options={addUnitOptions}
                                        discounts={discounts}
                                        errors={errors}
                                        onChange={setLine}
                                    />
                                </TabsContent>

                                <TabsContent
                                    value="add-ons"
                                    keepMounted
                                    className="pt-4"
                                >
                                    <BookingAddOnsSection
                                        lines={data.lines}
                                        options={addUnitOptions}
                                        chargeItems={chargeItems}
                                        arrival={data.arrival_date}
                                        departure={departure}
                                        currency={currency}
                                        errors={errors}
                                        onChange={setLine}
                                        onLinesChange={(lines) =>
                                            form.setData('lines', lines)
                                        }
                                    />
                                </TabsContent>

                                <TabsContent
                                    value="notes"
                                    keepMounted
                                    className="pt-4"
                                >
                                    <Field data-invalid={!!errors.notes}>
                                        <FieldLabel
                                            htmlFor="booking-notes"
                                            className="sr-only"
                                        >
                                            Notes
                                        </FieldLabel>
                                        <InputGroup>
                                            <InputGroupAddon
                                                align="block-start"
                                                className="border-b"
                                            >
                                                <NotebookText aria-hidden />
                                                <InputGroupText>
                                                    Special requests or internal
                                                    notes
                                                </InputGroupText>
                                            </InputGroupAddon>
                                            <InputGroupTextarea
                                                id="booking-notes"
                                                value={data.notes}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'notes',
                                                        event.target.value,
                                                    )
                                                }
                                                maxLength={NOTES_LIMIT}
                                                rows={4}
                                                placeholder="Add a note"
                                                aria-invalid={!!errors.notes}
                                            />
                                            <InputGroupAddon
                                                align="block-end"
                                                className="justify-end"
                                            >
                                                <InputGroupText className="tabular-nums">
                                                    {data.notes.length}/
                                                    {NOTES_LIMIT}
                                                </InputGroupText>
                                            </InputGroupAddon>
                                        </InputGroup>
                                        <FieldError>{errors.notes}</FieldError>
                                    </Field>
                                </TabsContent>
                            </Tabs>
                        </div>

                        <aside className="h-fit xl:sticky xl:top-4">
                            <BookingSummaryRail
                                units={units}
                                quotes={data.lines
                                    .map((line) => quotes[line.key])
                                    .filter(
                                        (quote): quote is Quote =>
                                            quote !== undefined &&
                                            quote.lineTotal !== null,
                                    )}
                                currency={currency}
                                billingModes={billingModes}
                                billingMode={data.billing_mode}
                                onBillingModeChange={(value) =>
                                    form.setData('billing_mode', value)
                                }
                                guests={data.guests.map((tab, index) => ({
                                    name: guestLabel(tab, index),
                                    unit:
                                        unitChoices[Number(tab.stays_in)]
                                            ?.label ?? null,
                                }))}
                                discountCount={
                                    data.lines.filter(
                                        (line) => line.discount_id !== '',
                                    ).length
                                }
                                unitsWithoutGuest={
                                    unitChoices.filter(
                                        (_, index) =>
                                            !data.guests.some(
                                                (tab) =>
                                                    tab.stays_in ===
                                                    String(index),
                                            ),
                                    ).length
                                }
                            />
                        </aside>
                    </div>
                </div>

                <FormActions fixed>
                    <Link
                        href={index.url()}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        <X data-icon="inline-start" />
                        Cancel
                    </Link>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        focusableWhenDisabled
                    >
                        {form.processing ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <CalendarPlus data-icon="inline-start" />
                        )}
                        Create booking
                    </Button>
                </FormActions>
            </form>

            <UnsavedChangesDialog
                guard={guard}
                name="this booking"
                saving={form.processing}
                onSave={() => submit()}
            />
        </>
    );
}

ReservationCreate.layout = (props: {
    isWalkIn: boolean;
    property: App.Data.Property.PropertySummaryData;
}) => ({
    variant: 'fixed' as const,
    // The property code is passed in because this runs before AppLayout sets the URL defaults, so server and browser
    // render the same links.
    breadcrumbs: [
        { title: 'Reservations', href: index(props.property.code) },
        props.isWalkIn
            ? { title: 'Walk-in', href: walkIn(props.property.code) }
            : { title: 'New booking', href: newMethod(props.property.code) },
    ],
});
