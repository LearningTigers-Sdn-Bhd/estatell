import { Head, Link, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { DateText } from '@/components/pms/date-text';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormActions } from '@/components/pms/form-actions';
import { GuestPicker } from '@/components/pms/guests/guest-picker';
import { PageHeader } from '@/components/pms/page-header';
import { BookingExtrasSection } from '@/components/pms/reservations/booking-extras-section';
import { BookingLineRow } from '@/components/pms/reservations/booking-line-row';
import {
    addDays,
    bookingPayload,
    emptyLine,
    nightsBetween,
    totalUnits,
} from '@/components/pms/reservations/booking-lines';
import type {
    BookingForm,
    BookingLine,
} from '@/components/pms/reservations/booking-lines';
import { BookingSummaryRail } from '@/components/pms/reservations/booking-summary-rail';
import { FormError } from '@/components/pms/reservations/form-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { index, newMethod, store, walkIn } from '@/routes/reservations';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';

type Quote = App.Data.Reservations.BookingQuoteData;

type Props = {
    isWalkIn: boolean;
    sources: EnumOption[];
    businessDate: string;
    addUnitOptions: App.Data.Reservations.AddUnitOptionsData;
    chargeItems: App.Data.Reservations.ChargeItemOptionData[];
    discounts: App.Data.Reservations.DiscountOptionData[];
    billingModes: EnumOption[];
    autoAssignRooms: boolean;
    currency: App.Enums.Currency | null;
    canOverrideRestrictions: boolean;
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
    businessDate,
    addUnitOptions,
    chargeItems,
    discounts,
    billingModes,
    autoAssignRooms,
    currency,
    canOverrideRestrictions,
}: Props) {
    const form = useForm<BookingForm>({
        source: sources[0]?.value ?? 'direct',
        external_reference: '',
        arrival_date: isWalkIn ? businessDate : '',
        departure_date: '',
        group_name: '',
        billing_mode: billingModes[0]?.value ?? 'master_pays',
        notes: '',
        booker_guest_id: '',
        lines: [emptyLine()],
    });
    const { data, errors } = form;
    const [booker, setBooker] =
        useState<App.Data.Guests.GuestOptionData | null>(null);
    const [quotes, setQuotes] = useState<Record<string, Quote>>({});
    const guard = useUnsavedChanges(form.isDirty && !form.processing);
    const roomTypes = addUnitOptions.roomTypes;
    const units = totalUnits(data.lines, roomTypes);
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
            onError: () =>
                document
                    .querySelector('[aria-invalid="true"]')
                    ?.scrollIntoView({ block: 'center', behavior: 'smooth' }),
        });
    };

    const guestError = errors.booker_guest_id;

    return (
        <>
            <Head title={isWalkIn ? 'Walk-in' : 'New booking'} />

            <form onSubmit={submit} className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={isWalkIn ? 'Walk-in' : 'New booking'}
                    description={
                        isWalkIn
                            ? 'A guest at the desk now. Every unit needs a room or bed.'
                            : undefined
                    }
                    back={{ href: index.url(), label: 'Reservations' }}
                />

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="flex flex-col gap-8">
                        <FormError errors={errors} />

                        <Section title="Stay">
                            <FieldGroup>
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <Field data-invalid={!!errors.arrival_date}>
                                        <FieldLabel htmlFor="booking-arrival">
                                            Arrival
                                        </FieldLabel>
                                        {isWalkIn ? (
                                            <p className="flex h-9 items-center text-sm">
                                                <DateText
                                                    date={data.arrival_date}
                                                />
                                            </p>
                                        ) : (
                                            <DatePicker
                                                id="booking-arrival"
                                                value={data.arrival_date}
                                                min={businessDate}
                                                onChange={(value) =>
                                                    form.setData(
                                                        'arrival_date',
                                                        value,
                                                    )
                                                }
                                                aria-invalid={
                                                    !!errors.arrival_date
                                                }
                                            />
                                        )}
                                        <FieldError>
                                            {errors.arrival_date}
                                        </FieldError>
                                    </Field>
                                    <Field
                                        data-invalid={!!errors.departure_date}
                                    >
                                        <FieldLabel htmlFor="booking-departure">
                                            Departure
                                        </FieldLabel>
                                        {lockedNights !== null ? (
                                            <p className="flex h-9 items-center text-sm">
                                                <DateText
                                                    date={
                                                        data.arrival_date
                                                            ? departure
                                                            : null
                                                    }
                                                />
                                            </p>
                                        ) : (
                                            <DatePicker
                                                id="booking-departure"
                                                value={data.departure_date}
                                                min={
                                                    data.arrival_date
                                                        ? addDays(
                                                              data.arrival_date,
                                                              1,
                                                          )
                                                        : undefined
                                                }
                                                onChange={(value) =>
                                                    form.setData(
                                                        'departure_date',
                                                        value,
                                                    )
                                                }
                                                aria-invalid={
                                                    !!errors.departure_date
                                                }
                                            />
                                        )}
                                        {lockedNights !== null && (
                                            <FieldDescription>
                                                Set by the package.
                                            </FieldDescription>
                                        )}
                                        <FieldError>
                                            {errors.departure_date}
                                        </FieldError>
                                    </Field>
                                    <Field>
                                        <FieldLabel>Nights</FieldLabel>
                                        <p className="flex h-9 items-center text-sm tabular-nums">
                                            {lockedNights !== null
                                                ? lockedNights
                                                : nights || '—'}
                                        </p>
                                    </Field>
                                    <Field data-invalid={!!errors.source}>
                                        <FieldLabel htmlFor="booking-source">
                                            Source
                                        </FieldLabel>
                                        <EnumSelect
                                            id="booking-source"
                                            options={sources}
                                            value={data.source}
                                            onValueChange={(value) =>
                                                form.setData('source', value)
                                            }
                                            disabled={sources.length === 1}
                                            aria-invalid={!!errors.source}
                                        />
                                        <FieldError>{errors.source}</FieldError>
                                    </Field>
                                </div>
                                <Field
                                    className="max-w-sm"
                                    data-invalid={!!errors.external_reference}
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
                                        autoComplete="off"
                                        aria-invalid={
                                            !!errors.external_reference
                                        }
                                    />
                                    <FieldError>
                                        {errors.external_reference}
                                    </FieldError>
                                </Field>
                            </FieldGroup>
                        </Section>

                        <Section title="Rooms and beds">
                            <div className="flex flex-col gap-3">
                                {data.lines.map((line, position) => (
                                    <BookingLineRow
                                        key={line.key}
                                        index={position}
                                        line={line}
                                        options={addUnitOptions}
                                        arrival={data.arrival_date}
                                        departure={departure}
                                        currency={currency}
                                        errors={errors}
                                        emptyPickLabel={emptyPickLabel}
                                        takenIds={takenBy(position)}
                                        canRemove={data.lines.length > 1}
                                        onChange={(patch) =>
                                            setLine(position, patch)
                                        }
                                        onRemove={() =>
                                            form.setData(
                                                'lines',
                                                data.lines.filter(
                                                    (_, at) => at !== position,
                                                ),
                                            )
                                        }
                                        onQuote={onQuote}
                                    />
                                ))}
                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            form.setData('lines', [
                                                ...data.lines,
                                                emptyLine(),
                                            ])
                                        }
                                    >
                                        <Plus data-icon="inline-start" />
                                        Add line
                                    </Button>
                                </div>
                                {warnings.length > 0 && (
                                    <Alert>
                                        <AlertDescription>
                                            <ul className="flex flex-col gap-1">
                                                {warnings.map((warning) => (
                                                    <li key={warning}>
                                                        {warning}
                                                    </li>
                                                ))}
                                            </ul>
                                            {canOverrideRestrictions
                                                ? 'You can book this anyway. Enter a reason for each line below.'
                                                : 'Ask someone who may book past restrictions.'}
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {askOverride &&
                                    data.lines.map((line, position) =>
                                        (quotes[line.key]?.violations.length ??
                                            0) > 0 ||
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
                                                    Restriction override reason,
                                                    line {position + 1}
                                                </FieldLabel>
                                                <Input
                                                    id={`line-${position}-restriction`}
                                                    value={
                                                        line.restriction_override_reason
                                                    }
                                                    onChange={(event) =>
                                                        setLine(position, {
                                                            restriction_override_reason:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    maxLength={500}
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

                        {units > 1 && (
                            <Section title="Group">
                                <Field
                                    className="max-w-sm"
                                    data-invalid={!!errors.group_name}
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
                                        placeholder="Tan family trip"
                                        autoComplete="off"
                                        aria-invalid={!!errors.group_name}
                                    />
                                    <FieldDescription>
                                        Leave empty to show the booker's name.
                                    </FieldDescription>
                                    <FieldError>{errors.group_name}</FieldError>
                                </Field>
                            </Section>
                        )}

                        <Section
                            title="Guests"
                            description="Pick the booker. Guests for each unit are attached from the reservation page."
                        >
                            <Field
                                className="max-w-md"
                                data-invalid={!!guestError}
                            >
                                <FieldLabel htmlFor="booking-booker">
                                    Booker
                                </FieldLabel>
                                <GuestPicker
                                    id="booking-booker"
                                    value={booker}
                                    onChange={(guest) => {
                                        setBooker(guest);
                                        form.setData(
                                            'booker_guest_id',
                                            guest ? String(guest.id) : '',
                                        );
                                    }}
                                    invalid={!!guestError}
                                />
                                <FieldError>{guestError}</FieldError>
                            </Field>
                        </Section>

                        <Section
                            title="Extras"
                            description="Optional. Add-ons and a discount for each line."
                        >
                            <BookingExtrasSection
                                lines={data.lines}
                                options={addUnitOptions}
                                chargeItems={chargeItems}
                                discounts={discounts}
                                arrival={data.arrival_date}
                                departure={departure}
                                currency={currency}
                                errors={errors}
                                onChange={setLine}
                            />
                        </Section>

                        <Section title="Notes">
                            <Field data-invalid={!!errors.notes}>
                                <FieldLabel
                                    htmlFor="booking-notes"
                                    className="sr-only"
                                >
                                    Notes
                                </FieldLabel>
                                <Textarea
                                    id="booking-notes"
                                    value={data.notes}
                                    onChange={(event) =>
                                        form.setData(
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={2000}
                                    rows={3}
                                    placeholder="Special requests or internal notes"
                                    aria-invalid={!!errors.notes}
                                />
                                <FieldError>{errors.notes}</FieldError>
                            </Field>
                        </Section>
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
                            bookerName={booker?.fullName ?? null}
                        />
                    </aside>
                </div>

                <FormActions>
                    <Link
                        href={index.url()}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        Cancel
                    </Link>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        focusableWhenDisabled
                    >
                        {form.processing && (
                            <Spinner data-icon="inline-start" />
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

ReservationCreate.layout = (props: { isWalkIn: boolean }) => ({
    breadcrumbs: [
        { title: 'Reservations', href: index() },
        props.isWalkIn
            ? { title: 'Walk-in', href: walkIn() }
            : { title: 'New booking', href: newMethod() },
    ],
});
