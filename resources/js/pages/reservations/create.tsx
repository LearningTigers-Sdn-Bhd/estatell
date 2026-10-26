import { Head, Link, useForm } from '@inertiajs/react';
import {
    Archive,
    ArrowRightLeft,
    Banknote,
    BedDouble,
    CalendarPlus,
    Clock,
    Footprints,
    History,
    LogIn,
    ListPlus,
    LogOut,
    Moon,
    NotebookText,
    Package,
    Percent,
    Plus,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { sourceSelectOptions } from '@/components/pms/reservations/booking-source-options';
import { FormActions } from '@/components/pms/form-actions';
import { MoneyInput } from '@/components/pms/money-input';
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
    beforeGoLiveRange,
    bookingPayload,
    bookingSeats,
    autoAddOns,
    emptyLine,
    nightsBetween,
    plansFor,
    totalUnits,
    unitOptions,
} from '@/components/pms/reservations/booking-lines';
import type {
    BookingForm,
    BookingLine,
} from '@/components/pms/reservations/booking-lines';
import { BookingSummaryRail } from '@/components/pms/reservations/booking-summary-rail';
import { useWalkInPayment } from '@/components/pms/reservations/use-walk-in-payment';
import { walkInPaymentPayload } from '@/components/pms/reservations/walk-in-payment';
import { WalkInCardSection } from '@/components/pms/reservations/walk-in-card-section';
import { WalkInPaymentSection } from '@/components/pms/reservations/walk-in-payment-section';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import {
    errorTabs,
    firstErrorTab,
    tabClass,
} from '@/components/pms/reservations/booking-tabs';
import type { BookingTab } from '@/components/pms/reservations/booking-tabs';
import { announceFormError, FormError } from '@/components/pms/form-error';
import { StayDateTime } from '@/components/pms/reservations/stay-date-time';
import { BookingBoatSelect } from '@/components/pms/reservations/booking-boat-select';
import {
    departuresFor,
    isScheduled,
    NOT_SET,
    pickedTime,
    type BoatChoice,
    type BoatDirection,
} from '@/components/pms/reservations/boat-trips';
import { useBoatSeats } from '@/components/pms/reservations/use-boat-seats';
import {
    DayUseLineRow,
    DayUseLinesHeader,
} from '@/components/pms/reservations/day-use-line-row';
import { nextHalfHour } from '@/components/pms/reservations/day-use';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { cn } from '@/lib/utils';
import { CreditOverrideField } from '@/components/pms/city-ledger/credit-override-field';
import { CompanySelect } from '@/components/pms/company-select';
import {
    index,
    migrated,
    newMethod,
    old,
    past,
    store,
    storePast,
    storeWalkIn,
    walkIn,
} from '@/routes/reservations';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';

/** How far ahead an arrival can be picked, and the longest stay the departure menu offers. */
const BOOKING_WINDOW_DAYS = 1095;
const LONGEST_STAY_DAYS = 365;

/** The longest note the server takes. */
const NOTES_LIMIT = 2000;

/** The departure that keeps the nights of a stay when its arrival moves. A stay with no nights (day use, or a missing date) keeps its departure. */
function departureKeepingNights(
    arrival: string,
    departure: string,
    newArrival: string,
): string {
    const nights = nightsBetween(arrival, departure);

    return nights >= 1 ? addDays(newArrival, nights) : departure;
}

/** Charging units that bill every night. A day use has no nights, so the server refuses them. */
const NIGHTLY_UNITS = ['per_night', 'per_guest_night'];

type Quote = App.Data.Reservations.BookingQuoteData;

type Props = {
    isWalkIn: boolean;
    /** Past stay mode: a stay that began before the business date and is still in the house. */
    isPastStay: boolean;
    /** Migrated stay mode (it shares the Past stay form): a guest who arrived before go-live and is in the house now. */
    isMigratedStay: boolean;
    /** Whether the user may enter a past stay (`folio.manual`): it adds the mode to the select. */
    canEnterPastStay: boolean;
    /** Whether Migrated stay shows in the select: `folio.manual` inside the migration window. */
    canEnterMigratedStay: boolean;
    /** Old stay mode (it shares the Past stay form): a stay that began and ended before go-live, kept for history only. */
    isOldStay: boolean;
    /** Where the back arrow and Cancel go: the page that opened the form, from `back` in the query. */
    back: App.Data.Reservations.BookingBackData;
    /** New booking only: what an empty Timeline cell passed in the query. Each part is already checked; a bad one is null. */
    prefill: {
        arrival: string | null;
        roomTypeId: number | null;
        roomId: number | null;
        bedId: number | null;
    } | null;
    /** Whether Old stay shows in the select: `folio.manual` inside the migration window. */
    canEnterOldStay: boolean;
    /** The ways to carry the old folio over, from `OldFolioMode::options()`. */
    oldFolioModes: EnumOption[];
    /** The go-live date, `Y-m-d`: the earliest arrival of a past stay. */
    goLiveDate: string;
    sources: App.Data.Reservations.BookingSourceOptionData[];
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
    companies: App.Data.CityLedger.CompanyOptionData[];
    canOverrideCredit: boolean;
    autoAssignRooms: boolean;
    currency: App.Data.Property.CurrencyData | null;
    canOverrideRestrictions: boolean;
    canManageGuests: boolean;
    /** Whether the user may take a payment (`payment.record`): it shows the walk-in Payment section. */
    canRecordPayment: boolean;
    /** Walk-in only: whether the property requires a signed card for check-in and the user may check in without one (`front-desk.check-in-without-card`). It shows the Registration card block. */
    canCheckInWithoutCard: boolean;
    /** The payment methods, asked for the first time the walk-in form opens. */
    paymentOptions?: App.Data.Payments.PaymentFormOptionsData | null;
    /** Whether the property has an active Day use plan: it turns the Stay type switch on. */
    hasDayUse: boolean;
    /** Whether the Boats feature is on: it shows the Boat in and Boat out picks on the Stay row. */
    hasBoats: boolean;
    /** The available boat departures, both directions. Each pick filters them by direction. */
    boatDepartures: App.Data.Boats.BoatDepartureOptionData[];
    countries: EnumOption[];
    /** The property's country code: the nationality a new guest starts with. */
    defaultNationalityCode: string;
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
    isPastStay,
    isMigratedStay,
    isOldStay,
    back,
    prefill,
    canEnterPastStay,
    canEnterMigratedStay,
    canEnterOldStay,
    oldFolioModes,
    goLiveDate,
    sources,
    today,
    checkInTime,
    checkOutTime,
    nowTime,
    addUnitOptions,
    chargeItems,
    discounts,
    billingModes,
    companies,
    canOverrideCredit,
    autoAssignRooms,
    currency,
    canOverrideRestrictions,
    canManageGuests,
    canRecordPayment,
    canCheckInWithoutCard,
    paymentOptions,
    hasDayUse,
    hasBoats,
    boatDepartures,
    countries,
    defaultNationalityCode,
    genders,
    locales,
}: Props) {
    const defaultSourceId = (past: boolean) =>
        String(
            (past && sources.find((source) => source.type === 'walk_in')?.id) ||
                sources[0]?.id ||
                '',
        );
    /** The first line: blank, or with the room or bed a Timeline cell picked. */
    function prefilledLine(): BookingLine {
        const line = emptyLine(autoAddOns(chargeItems, 'overnight'));

        if (prefill?.roomTypeId == null) {
            return line;
        }

        return {
            ...line,
            room_type_id: String(prefill.roomTypeId),
            room_id: prefill.roomId === null ? '' : String(prefill.roomId),
            bed_ids: prefill.bedId === null ? [] : [String(prefill.bedId)],
        };
    }
    const form = useForm<BookingForm>({
        stay_type: 'overnight',
        start_time: '',
        reason: '',
        status: 'in_house',
        mode: isOldStay ? 'old' : isMigratedStay ? 'migrated' : 'past',
        old_folio: 'one_amount',
        opening_amount: '',
        legacy_number: '',
        booking_source_id: defaultSourceId(isPastStay),
        external_reference: '',
        arrival_date: isOldStay
            ? addDays(goLiveDate, -2)
            : isMigratedStay
              ? addDays(goLiveDate, -1)
              : isPastStay
                ? addDays(today, -1)
                : (prefill?.arrival ?? today),
        departure_date: prefill?.arrival ? addDays(prefill.arrival, 1) : '',
        arrival_time: isWalkIn ? nowTime : checkInTime,
        departure_time: checkOutTime,
        group_name: '',
        company_id: '',
        credit_override_reason: '',
        billing_mode: billingModes[0]?.value ?? 'master_pays',
        notes: '',
        guests: [emptyGuest('', defaultNationalityCode)],
        lines: [prefilledLine()],
        boat_in: NOT_SET,
        boat_out: NOT_SET,
        without_card: false,
        collect_room_charges: true,
        collect_deposit: true,
        payment_method_id: '',
        reference: '',
        expected_total: '',
    });
    const { data, errors } = form;
    const [quotes, setQuotes] = useState<Record<string, Quote>>({});
    const [tab, setTab] = useState<BookingTab>('rooms');
    const guard = useUnsavedChanges(form.isDirty && !form.processing);
    const mode = isOldStay
        ? 'old'
        : isMigratedStay
          ? 'migrated'
          : isPastStay
            ? 'past'
            : isWalkIn
              ? 'walk-in'
              : 'new';
    const lastMode = useRef(mode);
    const modeOptions: EnumOption[] = [
        {
            value: 'new',
            label: 'New booking',
            icon: <CalendarPlus />,
            description: 'Reserve for a guest who arrives on a later date.',
        },
        {
            value: 'walk-in',
            label: 'Walk-in',
            icon: <Footprints />,
            description: 'A guest at the desk now. Checked in today.',
        },
        ...(canEnterPastStay
            ? [
                  {
                      value: 'past',
                      label: 'Past stay',
                      icon: <History />,
                      description:
                          'Began before today and was entered late. Every night is charged.',
                  },
              ]
            : []),
        ...(canEnterMigratedStay
            ? [
                  {
                      value: 'migrated',
                      label: 'Migrated stay',
                      icon: <ArrowRightLeft />,
                      description:
                          'Began in your old PMS, before go-live. Brings the old folio.',
                  },
              ]
            : []),
        ...(canEnterOldStay
            ? [
                  {
                      value: 'old',
                      label: 'Old stay',
                      icon: <Archive />,
                      description:
                          'Ended before go-live. History only, nothing charged.',
                  },
              ]
            : []),
    ];

    // A mode switch keeps the page mounted, so the form follows the new mode when the props change.
    useEffect(() => {
        if (lastMode.current === mode) {
            return;
        }

        lastMode.current = mode;
        form.setData((current) => ({
            ...current,
            booking_source_id: defaultSourceId(isPastStay),
            stay_type: isPastStay ? 'overnight' : current.stay_type,
            mode: isOldStay ? 'old' : isMigratedStay ? 'migrated' : 'past',
            status: 'in_house',
            arrival_date: isWalkIn
                ? today
                : isOldStay
                  ? addDays(goLiveDate, -2)
                  : isMigratedStay
                    ? addDays(goLiveDate, -1)
                    : isPastStay
                      ? addDays(today, -1)
                      : current.arrival_date,
            arrival_time: isWalkIn
                ? nowTime
                : isPastStay
                  ? checkInTime
                  : current.arrival_time,
            departure_date: isPastStay
                ? ''
                : isWalkIn
                  ? departureKeepingNights(
                        current.arrival_date,
                        current.departure_date,
                        today,
                    )
                  : current.departure_date,
            start_time:
                current.stay_type === 'day_use' && !isPastStay
                    ? defaultStart(isWalkIn)
                    : current.start_time,
        }));
    }, [mode]);

    /** A walk-in starts at the next half hour. A booking starts at 10:00 until staff pick another time. */
    function defaultStart(walkIn: boolean): string {
        return walkIn ? nextHalfHour(nowTime) : '10:00';
    }

    const switchStayType = (next: string) => {
        if (next === data.stay_type) {
            return;
        }

        // The plans and room types differ, so the lines start again. The Discounts tab does not exist for day use.
        form.setData((current) => ({
            ...current,
            stay_type: next as BookingForm['stay_type'],
            start_time: next === 'day_use' ? defaultStart(isWalkIn) : '',
            lines: [
                emptyLine(
                    autoAddOns(chargeItems, next as BookingForm['stay_type']),
                ),
            ],
        }));

        if (next === 'day_use' && tab === 'discounts') {
            setTab('rooms');
        }
    };

    const switchMode = (next: string) => {
        if (next === mode) {
            return;
        }

        const target =
            next === 'old'
                ? old
                : next === 'migrated'
                  ? migrated
                  : next === 'past'
                    ? past
                    : next === 'walk-in'
                      ? walkIn
                      : newMethod;

        guard.visitWithout(target.url(undefined, { query: back.query }), {
            preserveState: true,
            preserveScroll: true,
            only: [
                'isWalkIn',
                'isPastStay',
                'isMigratedStay',
                'isOldStay',
                'sources',
                'nowTime',
            ],
        });
    };
    const sourceType = sources.find(
        (source) => String(source.id) === data.booking_source_id,
    )?.type;
    const isDayUse = data.stay_type === 'day_use' && !isPastStay;
    const isCheckedOut = isPastStay && data.status === 'checked_out';
    // A refusal of the start of a block can come back on the form or on a line.
    const startError =
        errors.start_time ??
        Object.entries(errors).find(([key]) =>
            /^lines\.\d+\.start_time$/.test(key),
        )?.[1];
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
    const departure = isDayUse
        ? data.arrival_date
        : lockedNights !== null && data.arrival_date
          ? addDays(data.arrival_date, lockedNights)
          : data.departure_date;
    const showPayment = isWalkIn && canRecordPayment;
    useFormOptions(showPayment, paymentOptions !== undefined, 'paymentOptions');
    const [paymentRefresh, setPaymentRefresh] = useState(0);
    const walkInPayment = useWalkInPayment(
        showPayment
            ? walkInPaymentPayload(
                  { ...data, departure_date: departure },
                  roomTypes,
              )
            : null,
        paymentRefresh,
    );
    // The total posted with the form is the one the rail shows, so a save waits for the rail's answer while a box is ticked.
    const waitingForPayment =
        showPayment &&
        walkInPayment.loading &&
        Boolean(data.collect_room_charges || data.collect_deposit);
    const emptyPickLabel =
        isPastStay || (isWalkIn && !autoAssignRooms)
            ? 'Select'
            : autoAssignRooms
              ? 'Auto-assign'
              : 'Select later';

    // The seats are for the dates the stay takes: Boat in on the arrival, Boat out on the departure.
    const boatInSeats = useBoatSeats(
        'in',
        hasBoats && !isDayUse ? data.arrival_date : '',
    );
    const boatOutSeats = useBoatSeats(
        'out',
        hasBoats && !isDayUse ? departure : '',
    );
    const party = bookingSeats(data.lines, roomTypes);

    /** A Boat in or Boat out pick. A scheduled departure also sets the Stay row time, which then locks. */
    const setBoat = (direction: BoatDirection, choice: BoatChoice) =>
        form.setData((current) => {
            const next = {
                ...current,
                ...(direction === 'in'
                    ? { boat_in: choice }
                    : { boat_out: choice }),
            };
            const time = pickedTime(choice, boatDepartures);

            if (time === null) {
                return next;
            }

            return direction === 'in'
                ? { ...next, arrival_time: time }
                : { ...next, departure_time: time };
        });

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

    const pageTitle = isOldStay
        ? 'Old stay'
        : isMigratedStay
          ? 'Migrated stay'
          : isPastStay
            ? 'Past stay'
            : isWalkIn
              ? 'Walk-in'
              : 'New booking';

    const submit = (event?: React.FormEvent) => {
        event?.preventDefault();
        form.transform(
            (values) =>
                bookingPayload(
                    { ...values, departure_date: departure },
                    roomTypes,
                ) as unknown as BookingForm,
        );
        form.post(
            (isPastStay ? storePast : isWalkIn ? storeWalkIn : store).url(),
            {
                preserveScroll: true,
                except: KEEP_OPTIONS,
                onError: (submitted) => {
                    if (submitted.amount_changed) {
                        // The rates, taxes or deposit changed since the rail asked: ask again.
                        setPaymentRefresh((count) => count + 1);
                    }

                    const failing = firstErrorTab(errorTabs(submitted));

                    if (failing !== null) {
                        setTab(failing);
                    }

                    if (announceFormError(submitted)) {
                        return;
                    }

                    document
                        .querySelector('[aria-invalid="true"]')
                        ?.scrollIntoView({
                            block: 'center',
                            behavior: 'smooth',
                        });
                },
            },
        );
    };

    return (
        <>
            <Head title={pageTitle} />

            <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
                <div
                    scroll-region=""
                    className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 pb-12"
                >
                    <PageHeader
                        title={pageTitle}
                        description={
                            isOldStay
                                ? 'A stay that began and ended before go-live, kept for history only. Every unit needs a room or bed.'
                                : isMigratedStay
                                  ? 'A guest who arrived before go-live. Every unit needs a room or bed.'
                                  : isPastStay
                                    ? 'A stay that began before today and is still in the house. Every unit needs a room or bed.'
                                    : isWalkIn
                                      ? 'A guest at the desk now. Every unit needs a room or bed.'
                                      : 'Reserve rooms or beds for a guest who arrives on a later date.'
                        }
                        back={{ href: back.href, label: back.label }}
                    />

                    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                        <div className="flex flex-col gap-8">
                            <FormError errors={errors} />

                            <Section
                                title="Stay"
                                description="The dates and expected times apply to every line."
                            >
                                <FieldGroup>
                                    {hasDayUse && !isPastStay && (
                                        <Field>
                                            <FieldLabel>Stay type</FieldLabel>
                                            <ToggleGroup
                                                variant="outline"
                                                spacing={0}
                                                value={[data.stay_type]}
                                                onValueChange={(value) =>
                                                    value[0] &&
                                                    switchStayType(value[0])
                                                }
                                                aria-label="Stay type"
                                            >
                                                <ToggleGroupItem value="overnight">
                                                    <Moon data-icon="inline-start" />
                                                    Overnight
                                                </ToggleGroupItem>
                                                <ToggleGroupItem value="day_use">
                                                    <Clock data-icon="inline-start" />
                                                    Day use
                                                </ToggleGroupItem>
                                            </ToggleGroup>
                                            <FieldDescription>
                                                {isDayUse
                                                    ? 'A room for a number of hours on one day. It applies to every line.'
                                                    : 'Rooms or beds for one night or more.'}
                                            </FieldDescription>
                                        </Field>
                                    )}
                                    {isDayUse ? (
                                        <div className="grid items-start gap-4 sm:grid-cols-[minmax(0,1fr)_12rem]">
                                            <Field
                                                data-invalid={
                                                    !!errors.arrival_date ||
                                                    !!startError
                                                }
                                            >
                                                <FieldLabel htmlFor="booking-day-use-date">
                                                    Date and start
                                                </FieldLabel>
                                                <StayDateTime
                                                    id="booking-day-use-date"
                                                    date={data.arrival_date}
                                                    time={data.start_time}
                                                    timeLabel="Start time"
                                                    dateLocked={isWalkIn}
                                                    min={today}
                                                    max={lastBookable}
                                                    step={1800}
                                                    onDateChange={(value) =>
                                                        form.setData(
                                                            'arrival_date',
                                                            value,
                                                        )
                                                    }
                                                    onTimeChange={(value) =>
                                                        form.setData(
                                                            'start_time',
                                                            value,
                                                        )
                                                    }
                                                    aria-invalid={
                                                        !!errors.arrival_date
                                                    }
                                                    timeInvalid={!!startError}
                                                />
                                                <FieldDescription>
                                                    The block starts on the hour
                                                    or the half hour and ends
                                                    the same day.
                                                </FieldDescription>
                                                <FieldError>
                                                    {errors.arrival_date ??
                                                        startError}
                                                </FieldError>
                                            </Field>
                                            <Field>
                                                <FieldLabel htmlFor="booking-mode">
                                                    Reservation mode
                                                </FieldLabel>
                                                <EnumSelect
                                                    id="booking-mode"
                                                    options={modeOptions}
                                                    value={mode}
                                                    onValueChange={switchMode}
                                                    menuClassName="w-80"
                                                />
                                            </Field>
                                        </div>
                                    ) : (
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
                                                    timeLocked={isScheduled(
                                                        data.boat_in,
                                                    )}
                                                    dateLocked={isWalkIn}
                                                    min={
                                                        isMigratedStay ||
                                                        isOldStay
                                                            ? addDays(
                                                                  goLiveDate,
                                                                  -LONGEST_STAY_DAYS,
                                                              )
                                                            : isPastStay
                                                              ? goLiveDate
                                                              : today
                                                    }
                                                    max={
                                                        isOldStay
                                                            ? addDays(
                                                                  goLiveDate,
                                                                  -2,
                                                              )
                                                            : isMigratedStay
                                                              ? addDays(
                                                                    goLiveDate,
                                                                    -1,
                                                                )
                                                              : isPastStay
                                                                ? addDays(
                                                                      today,
                                                                      -1,
                                                                  )
                                                                : lastBookable
                                                    }
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
                                                    timeLocked={isScheduled(
                                                        data.boat_out,
                                                    )}
                                                    dateLocked={
                                                        lockedNights !== null
                                                    }
                                                    min={
                                                        data.arrival_date &&
                                                        !(
                                                            isPastStay &&
                                                            !isCheckedOut &&
                                                            !isOldStay
                                                        )
                                                            ? isMigratedStay &&
                                                              goLiveDate >
                                                                  addDays(
                                                                      data.arrival_date,
                                                                      1,
                                                                  )
                                                                ? goLiveDate
                                                                : addDays(
                                                                      data.arrival_date,
                                                                      1,
                                                                  )
                                                            : addDays(today, 1)
                                                    }
                                                    max={
                                                        isOldStay
                                                            ? addDays(
                                                                  goLiveDate,
                                                                  -1,
                                                              )
                                                            : isCheckedOut
                                                              ? today
                                                              : addDays(
                                                                    data.arrival_date ||
                                                                        today,
                                                                    LONGEST_STAY_DAYS,
                                                                )
                                                    }
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
                                                    options={modeOptions}
                                                    value={mode}
                                                    onValueChange={switchMode}
                                                    menuClassName="w-80"
                                                />
                                            </Field>
                                        </div>
                                    )}
                                    {isPastStay && !isOldStay && (
                                        <Field>
                                            <FieldLabel>
                                                Guest status
                                            </FieldLabel>
                                            <ToggleGroup
                                                variant="outline"
                                                spacing={0}
                                                value={[
                                                    data.status ?? 'in_house',
                                                ]}
                                                onValueChange={(value) =>
                                                    value[0] &&
                                                    form.setData((current) => ({
                                                        ...current,
                                                        status: value[0] as
                                                            | 'in_house'
                                                            | 'checked_out',
                                                        departure_date: '',
                                                    }))
                                                }
                                                aria-label="Guest status"
                                            >
                                                <ToggleGroupItem value="in_house">
                                                    <BedDouble data-icon="inline-start" />
                                                    Still in house
                                                </ToggleGroupItem>
                                                <ToggleGroupItem value="checked_out">
                                                    <LogOut data-icon="inline-start" />
                                                    Already checked out
                                                </ToggleGroupItem>
                                            </ToggleGroup>
                                            <FieldDescription>
                                                {isCheckedOut
                                                    ? isMigratedStay
                                                        ? 'The guest left on or after go-live. The folio stays open with the old balance.'
                                                        : 'The guest has left. The folio stays open so you can enter the payments.'
                                                    : 'The guest is in the house now. Departure is after today.'}
                                            </FieldDescription>
                                        </Field>
                                    )}
                                    {isOldStay && (
                                        <p className="text-sm text-muted-foreground">
                                            History only. Nothing is charged and
                                            no folio lines are made.
                                        </p>
                                    )}
                                    {isMigratedStay && (
                                        <div className="flex flex-col gap-4 rounded-lg border p-4">
                                            {beforeGoLiveRange(
                                                data.arrival_date,
                                                goLiveDate,
                                            ) !== '' && (
                                                <p className="text-sm text-muted-foreground">
                                                    Nights before go-live (
                                                    {beforeGoLiveRange(
                                                        data.arrival_date,
                                                        goLiveDate,
                                                    )}
                                                    ) are not charged here.
                                                </p>
                                            )}
                                            <Field>
                                                <FieldLabel>
                                                    Bring in the old folio
                                                </FieldLabel>
                                                <ToggleGroup
                                                    variant="outline"
                                                    spacing={0}
                                                    value={[
                                                        data.old_folio ??
                                                            'one_amount',
                                                    ]}
                                                    onValueChange={(value) =>
                                                        value[0] &&
                                                        form.setData(
                                                            'old_folio',
                                                            value[0],
                                                        )
                                                    }
                                                    aria-label="Bring in the old folio"
                                                >
                                                    {oldFolioModes.map(
                                                        (option) => (
                                                            <ToggleGroupItem
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.value ===
                                                                'copy_line_by_line' ? (
                                                                    <ListPlus data-icon="inline-start" />
                                                                ) : (
                                                                    <Banknote data-icon="inline-start" />
                                                                )}
                                                                {option.label}
                                                            </ToggleGroupItem>
                                                        ),
                                                    )}
                                                </ToggleGroup>
                                                <FieldDescription>
                                                    {data.old_folio ===
                                                    'copy_line_by_line'
                                                        ? 'Enter each charge and payment as your old folio shows it. Opens the old folio page after saving.'
                                                        : 'One opening line with the balance from your old system.'}
                                                </FieldDescription>
                                            </Field>
                                            {data.old_folio ===
                                            'copy_line_by_line' ? null : (
                                                <div className="grid items-start gap-4 sm:grid-cols-2">
                                                    <Field
                                                        data-invalid={
                                                            !!errors.opening_amount
                                                        }
                                                    >
                                                        <FieldLabel htmlFor="booking-opening-amount">
                                                            Amount
                                                        </FieldLabel>
                                                        <MoneyInput
                                                            id="booking-opening-amount"
                                                            value={
                                                                data.opening_amount ??
                                                                ''
                                                            }
                                                            onChange={(value) =>
                                                                form.setData(
                                                                    'opening_amount',
                                                                    value,
                                                                )
                                                            }
                                                            currency={currency}
                                                            placeholder="e.g. 1,080.00"
                                                            invalid={
                                                                !!errors.opening_amount
                                                            }
                                                        />
                                                        <FieldDescription>
                                                            What the guest still
                                                            owes in your old
                                                            system. Enter a
                                                            minus amount for a
                                                            credit.
                                                        </FieldDescription>
                                                        <FieldError>
                                                            {
                                                                errors.opening_amount
                                                            }
                                                        </FieldError>
                                                    </Field>
                                                    <Field
                                                        data-invalid={
                                                            !!errors.legacy_number
                                                        }
                                                    >
                                                        <FieldLabel htmlFor="booking-legacy-number">
                                                            Old folio no.
                                                        </FieldLabel>
                                                        <Input
                                                            id="booking-legacy-number"
                                                            name="legacy_number"
                                                            value={
                                                                data.legacy_number ??
                                                                ''
                                                            }
                                                            onChange={(event) =>
                                                                form.setData(
                                                                    'legacy_number',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            placeholder="e.g. F1203"
                                                            maxLength={50}
                                                            aria-invalid={
                                                                !!errors.legacy_number
                                                            }
                                                        />
                                                        <FieldError>
                                                            {
                                                                errors.legacy_number
                                                            }
                                                        </FieldError>
                                                    </Field>
                                                </div>
                                            )}
                                            <FieldError>
                                                {errors.old_folio}
                                            </FieldError>
                                        </div>
                                    )}
                                    {isPastStay && (
                                        <Field data-invalid={!!errors.reason}>
                                            <FieldLabel htmlFor="booking-reason">
                                                Reason
                                            </FieldLabel>
                                            <Input
                                                id="booking-reason"
                                                name="reason"
                                                value={data.reason ?? ''}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'reason',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder={
                                                    isOldStay
                                                        ? 'e.g. Stay before go-live, from old PMS'
                                                        : isMigratedStay
                                                          ? 'e.g. Migrated from old PMS'
                                                          : 'e.g. Walk-in entered late'
                                                }
                                                maxLength={500}
                                                aria-invalid={!!errors.reason}
                                            />
                                            {!isMigratedStay && !isOldStay && (
                                                <FieldDescription>
                                                    Nights before {today} post
                                                    now with their own dates.
                                                </FieldDescription>
                                            )}
                                            <FieldError>
                                                {errors.reason}
                                            </FieldError>
                                        </Field>
                                    )}
                                    {!isDayUse && hasBoats && !isPastStay && (
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <BookingBoatSelect
                                                id="booking-boat-in"
                                                label="Boat in"
                                                value={data.boat_in}
                                                onChange={(choice) =>
                                                    setBoat('in', choice)
                                                }
                                                departures={departuresFor(
                                                    boatDepartures,
                                                    'in',
                                                )}
                                                seats={boatInSeats.seats}
                                                seatsLoaded={boatInSeats.loaded}
                                                bookingSeats={party}
                                                error={errors.boat_in}
                                            />
                                            <BookingBoatSelect
                                                id="booking-boat-out"
                                                label="Boat out"
                                                value={data.boat_out}
                                                onChange={(choice) =>
                                                    setBoat('out', choice)
                                                }
                                                departures={departuresFor(
                                                    boatDepartures,
                                                    'out',
                                                )}
                                                seats={boatOutSeats.seats}
                                                seatsLoaded={
                                                    boatOutSeats.loaded
                                                }
                                                bookingSeats={party}
                                                error={errors.boat_out}
                                            />
                                        </div>
                                    )}
                                    <div
                                        className={cn(
                                            'grid gap-4 sm:grid-cols-2',
                                            !(
                                                companies.length > 0 &&
                                                units > 1
                                            ) && 'lg:grid-cols-3',
                                        )}
                                    >
                                        <Field
                                            data-invalid={
                                                !!errors.booking_source_id
                                            }
                                        >
                                            <FieldLabel htmlFor="booking-source">
                                                Source
                                            </FieldLabel>
                                            <EnumSelect
                                                id="booking-source"
                                                options={sourceSelectOptions(
                                                    sources,
                                                )}
                                                value={data.booking_source_id}
                                                onValueChange={(value) =>
                                                    form.setData(
                                                        'booking_source_id',
                                                        value,
                                                    )
                                                }
                                                disabled={sources.length === 1}
                                                aria-invalid={
                                                    !!errors.booking_source_id
                                                }
                                            />
                                            <FieldError>
                                                {errors.booking_source_id}
                                            </FieldError>
                                        </Field>
                                        <Field
                                            data-invalid={
                                                !!errors.external_reference
                                            }
                                        >
                                            <FieldLabel htmlFor="booking-external_reference">
                                                External reference
                                                {sourceType === 'ota' &&
                                                    ' (required)'}
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
                                        {companies.length > 0 && (
                                            <Field
                                                data-invalid={
                                                    !!errors.company_id
                                                }
                                            >
                                                <FieldLabel htmlFor="booking-company_id">
                                                    Company
                                                    {sourceType === 'company' &&
                                                        ' (required)'}
                                                </FieldLabel>
                                                <CompanySelect
                                                    id="booking-company_id"
                                                    value={data.company_id}
                                                    onValueChange={(value) =>
                                                        form.setData({
                                                            ...data,
                                                            company_id: value,
                                                            lines: data.lines.map(
                                                                (line) =>
                                                                    plansFor(
                                                                        addUnitOptions.ratePlans,
                                                                        '',
                                                                        value,
                                                                    ).some(
                                                                        (
                                                                            plan,
                                                                        ) =>
                                                                            String(
                                                                                plan.id,
                                                                            ) ===
                                                                            line.rate_plan_id,
                                                                    )
                                                                        ? line
                                                                        : {
                                                                              ...line,
                                                                              rate_plan_id:
                                                                                  '',
                                                                          },
                                                            ),
                                                        })
                                                    }
                                                    companies={companies}
                                                    invalid={
                                                        !!errors.company_id
                                                    }
                                                />
                                                <FieldError>
                                                    {errors.company_id}
                                                </FieldError>
                                                <CreditOverrideField
                                                    id="booking-credit_override_reason"
                                                    error={
                                                        errors.credit_override_reason
                                                    }
                                                    canOverride={
                                                        canOverrideCredit
                                                    }
                                                    value={
                                                        data.credit_override_reason
                                                    }
                                                    onChange={(value) =>
                                                        form.setData(
                                                            'credit_override_reason',
                                                            value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                        )}
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
                                    {!isDayUse && (
                                        <TabsTrigger
                                            value="discounts"
                                            className={tabClass(
                                                tabErrors.discounts,
                                            )}
                                        >
                                            <Percent data-icon="inline-start" />
                                            Discounts
                                        </TabsTrigger>
                                    )}
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
                                        title={
                                            isDayUse
                                                ? 'Rooms'
                                                : 'Rooms and beds'
                                        }
                                        description={
                                            isDayUse
                                                ? 'One line for each room: the day use plan, the hours and the room. Every line starts at the time above.'
                                                : 'One line for each room type and rate plan. A dorm line sells beds.'
                                        }
                                    >
                                        <div className="flex flex-col gap-3">
                                            <div className="overflow-hidden rounded-lg border">
                                                {isDayUse ? (
                                                    <>
                                                        <DayUseLinesHeader />
                                                        <div className="divide-y">
                                                            {data.lines.map(
                                                                (
                                                                    line,
                                                                    position,
                                                                ) => (
                                                                    <DayUseLineRow
                                                                        key={
                                                                            line.key
                                                                        }
                                                                        index={
                                                                            position
                                                                        }
                                                                        line={
                                                                            line
                                                                        }
                                                                        options={
                                                                            addUnitOptions
                                                                        }
                                                                        companyId={
                                                                            data.company_id
                                                                        }
                                                                        date={
                                                                            data.arrival_date
                                                                        }
                                                                        start={
                                                                            data.start_time
                                                                        }
                                                                        currency={
                                                                            currency
                                                                        }
                                                                        errors={
                                                                            errors
                                                                        }
                                                                        takenIds={takenBy(
                                                                            position,
                                                                        )}
                                                                        canRemove={
                                                                            data
                                                                                .lines
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
                                                    </>
                                                ) : (
                                                    <>
                                                        <BookingLinesHeader />
                                                        <div className="divide-y">
                                                            {data.lines.map(
                                                                (
                                                                    line,
                                                                    position,
                                                                ) => (
                                                                    <BookingLineRow
                                                                        key={
                                                                            line.key
                                                                        }
                                                                        index={
                                                                            position
                                                                        }
                                                                        line={
                                                                            line
                                                                        }
                                                                        options={
                                                                            addUnitOptions
                                                                        }
                                                                        companyId={
                                                                            data.company_id
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
                                                                        errors={
                                                                            errors
                                                                        }
                                                                        goLiveDate={
                                                                            isMigratedStay ||
                                                                            isOldStay
                                                                                ? goLiveDate
                                                                                : null
                                                                        }
                                                                        emptyPickLabel={
                                                                            emptyPickLabel
                                                                        }
                                                                        walkIn={
                                                                            isWalkIn
                                                                        }
                                                                        takenIds={takenBy(
                                                                            position,
                                                                        )}
                                                                        canRemove={
                                                                            data
                                                                                .lines
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
                                                    </>
                                                )}
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
                                                                    emptyLine(
                                                                        autoAddOns(
                                                                            chargeItems,
                                                                            data.stay_type,
                                                                        ),
                                                                    ),
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
                                            defaultNationalityCode={
                                                defaultNationalityCode
                                            }
                                            genders={genders}
                                            locales={locales}
                                            errors={errors}
                                            onChange={(guests) =>
                                                form.setData('guests', guests)
                                            }
                                        />
                                    </Section>
                                </TabsContent>

                                {!isDayUse && (
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
                                )}

                                <TabsContent
                                    value="add-ons"
                                    keepMounted
                                    className="pt-4"
                                >
                                    <BookingAddOnsSection
                                        lines={data.lines}
                                        options={addUnitOptions}
                                        chargeItems={
                                            isDayUse
                                                ? chargeItems.filter(
                                                      (item) =>
                                                          !NIGHTLY_UNITS.includes(
                                                              item.chargingUnit,
                                                          ),
                                                  )
                                                : chargeItems
                                        }
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
                                companyName={
                                    companies.find(
                                        (company) =>
                                            String(company.id) ===
                                            data.company_id,
                                    )?.name ?? null
                                }
                                onBillingModeChange={(value) =>
                                    form.setData('billing_mode', value)
                                }
                                guests={data.guests.map((tab, index) => ({
                                    name: guestLabel(tab, index),
                                    unit:
                                        unitChoices[Number(tab.stays_in)]
                                            ?.label ?? null,
                                }))}
                                registrationCard={
                                    isWalkIn && canCheckInWithoutCard ? (
                                        <WalkInCardSection
                                            checked={data.without_card ?? false}
                                            onChange={(checked) =>
                                                form.setData(
                                                    'without_card',
                                                    checked,
                                                )
                                            }
                                        />
                                    ) : undefined
                                }
                                payment={
                                    showPayment ? (
                                        <WalkInPaymentSection
                                            payment={walkInPayment.payment}
                                            loading={walkInPayment.loading}
                                            invalid={walkInPayment.invalid}
                                            options={paymentOptions}
                                            currency={currency}
                                            values={{
                                                collect_room_charges:
                                                    data.collect_room_charges ??
                                                    true,
                                                collect_deposit:
                                                    data.collect_deposit ??
                                                    true,
                                                payment_method_id:
                                                    data.payment_method_id ??
                                                    '',
                                                reference: data.reference ?? '',
                                                expected_total:
                                                    data.expected_total ?? '',
                                            }}
                                            errors={errors}
                                            onChange={(patch) =>
                                                form.setData((current) => ({
                                                    ...current,
                                                    ...patch,
                                                }))
                                            }
                                            onDerive={(patch) => {
                                                form.setData((current) => ({
                                                    ...current,
                                                    ...patch,
                                                }));
                                                form.setDefaults(patch);
                                            }}
                                        />
                                    ) : undefined
                                }
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
                        href={back.href}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        <X data-icon="inline-start" />
                        Cancel
                    </Link>
                    <Button
                        type="submit"
                        disabled={form.processing || waitingForPayment}
                        focusableWhenDisabled
                    >
                        {form.processing ? (
                            <Spinner data-icon="inline-start" />
                        ) : isWalkIn ? (
                            <LogIn data-icon="inline-start" />
                        ) : (
                            <CalendarPlus data-icon="inline-start" />
                        )}
                        {isWalkIn
                            ? 'Check in'
                            : isOldStay
                              ? 'Save old stay'
                              : isCheckedOut && !isMigratedStay
                                ? 'Save and open Manual Folio Editor'
                                : isPastStay
                                  ? 'Save and open folio'
                                  : 'Create booking'}
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
    isPastStay: boolean;
    isMigratedStay: boolean;
    isOldStay: boolean;
    property: App.Data.Property.PropertySummaryData;
}) => ({
    variant: 'fixed' as const,
    // The property code is passed in because this runs before AppLayout sets the URL defaults, so server and browser
    // render the same links.
    breadcrumbs: [
        { title: 'Reservations', href: index(props.property.code) },
        props.isOldStay
            ? { title: 'Old stay', href: old(props.property.code) }
            : props.isMigratedStay
              ? { title: 'Migrated stay', href: migrated(props.property.code) }
              : props.isPastStay
                ? { title: 'Past stay', href: past(props.property.code) }
                : props.isWalkIn
                  ? { title: 'Walk-in', href: walkIn(props.property.code) }
                  : {
                        title: 'New booking',
                        href: newMethod(props.property.code),
                    },
    ],
});
