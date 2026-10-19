import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormError } from '@/components/pms/form-error';
import { BookGuestsTable } from '@/components/pms/portal/book-guests-table';
import {
    BookLineRow,
    BookLinesHeader,
} from '@/components/pms/portal/book-line-row';
import {
    MAX_LINES,
    emptyGuest,
    newLine,
    nightsBetween,
    offerLines,
    shiftDate,
    sumAmounts,
    unitCount,
    unitSlots,
} from '@/components/pms/portal/book-lines';
import type { BookGuest, BookLine } from '@/components/pms/portal/book-lines';
import { BookSummary } from '@/components/pms/portal/book-summary';
import { usePortalOffers } from '@/components/pms/portal/use-portal-offers';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store } from '@/routes/portal/bookings';

type RatePlan = App.Data.Portal.PortalRatePlanData;
type RoomType = App.Data.Portal.PortalRoomTypeData;

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

/**
 * The book page, in the layout of the staff booking form: Stay, the lines table, the guests table and a summary rail.
 * Each line picks a rate plan first, then a room type it sells. Prices come from the server after each change; the
 * server prices and checks everything again on Confirm, and a changed price asks for new prices.
 * See docs/23-company-portal/01-company-portal.md#booking.
 */
export function BookForm({
    propertyCode,
    ratePlans,
    roomTypes,
    idTypes,
    today,
    cancelCutoffDays,
    autoAddOns,
    companyName,
    currency,
}: {
    propertyCode: string;
    ratePlans: RatePlan[];
    roomTypes: RoomType[];
    idTypes: EnumOption[];
    today: string;
    cancelCutoffDays: number | null;
    autoAddOns: string[];
    companyName: string;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const onlyPlan = ratePlans.length === 1 ? String(ratePlans[0].id) : '';
    const [initial] = useState(() => ({
        arrival_date: '',
        departure_date: '',
        lines: [newLine(onlyPlan)] as BookLine[],
        guests: {} as Record<string, BookGuest>,
        group_name: '',
        external_reference: '',
    }));
    const form = useForm(initial);
    const [refresh, setRefresh] = useState(0);
    const { data } = form;

    const roomTypeOf = (roomTypeId: string) =>
        roomTypes.find((type) => String(type.id) === roomTypeId);
    const isBed = (roomTypeId: string) =>
        roomTypeOf(roomTypeId)?.sellUnit === 'bed';
    const nights = nightsBetween(data.arrival_date, data.departure_date);
    const payload =
        nights > 0 && data.lines.some((line) => line.rate_plan_id !== '')
            ? {
                  arrival_date: data.arrival_date,
                  departure_date: data.departure_date,
                  lines: offerLines(data.lines, isBed),
              }
            : null;
    const offers = usePortalOffers(propertyCode, payload, refresh);
    const errors = { ...offers.errors, ...form.errors } as Record<
        string,
        string | undefined
    >;

    const slots = unitSlots(
        data.lines,
        (roomTypeId) => roomTypeOf(roomTypeId)?.name,
        isBed,
    );
    const picked = data.lines.map((line, index) =>
        offers.lines?.[index]?.offers.find(
            (offer) => String(offer.roomTypeId) === line.room_type_id,
        ),
    );
    const quotes = picked.map((offer) =>
        offer && offer.reason === null ? offer.quote : null,
    );
    const rooms = sumAmounts(quotes.map((quote) => quote?.lineTotal ?? null));
    const addOns = sumAmounts(
        quotes.map((quote) => quote?.addOnsTotal ?? null),
    );
    const total = sumAmounts([rooms, addOns]);
    const unnamed = slots.filter(
        (slot) => (data.guests[slot.key]?.full_name ?? '').trim() === '',
    ).length;
    const canConfirm =
        offers.fresh &&
        !offers.loading &&
        slots.length > 0 &&
        unnamed === 0 &&
        data.lines.every(
            (line, index) =>
                line.room_type_id !== '' && quotes[index]?.lineTotal != null,
        );
    const freeUntil =
        cancelCutoffDays !== null && data.arrival_date !== ''
            ? shiftDate(data.arrival_date, -cancelCutoffDays)
            : null;

    const setLine = (index: number, patch: Partial<BookLine>) => {
        form.setData(
            'lines',
            data.lines.map((line, position) =>
                position === index ? { ...line, ...patch } : line,
            ),
        );
    };

    const setGuest = (key: string, patch: Partial<BookGuest>) => {
        form.setData('guests', {
            ...data.guests,
            [key]: { ...(data.guests[key] ?? emptyGuest()), ...patch },
        });
    };

    const setDates = (arrival: string, departure: string) => {
        form.setData({
            ...data,
            arrival_date: arrival,
            departure_date:
                arrival !== '' && departure <= arrival
                    ? shiftDate(arrival, 1)
                    : departure,
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((current) => ({
            arrival_date: current.arrival_date,
            departure_date: current.departure_date,
            group_name: current.group_name,
            external_reference: current.external_reference,
            expected_total: total,
            lines: offerLines(current.lines, isBed),
            guests: slots.map((slot) => {
                const guest = current.guests[slot.key] ?? emptyGuest();

                return {
                    full_name: guest.full_name,
                    phone: guest.phone,
                    id_type: guest.id_type === '' ? null : guest.id_type,
                    id_number: guest.id_number === '' ? null : guest.id_number,
                };
            }),
        }));
        form.post(store.url({ property: propertyCode }), {
            preserveScroll: true,
            onError: () => setRefresh((value) => value + 1),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]"
        >
            <div className="flex flex-col gap-8">
                <FormError errors={errors} />

                <Section
                    title="Stay"
                    description="The dates apply to every line."
                >
                    <FieldGroup>
                        <div className="grid items-start gap-4 sm:grid-cols-[minmax(0,16rem)_auto_minmax(0,16rem)]">
                            <Field data-invalid={!!errors.arrival_date}>
                                <FieldLabel htmlFor="book-arrival">
                                    Arrival
                                </FieldLabel>
                                <DatePicker
                                    id="book-arrival"
                                    value={data.arrival_date}
                                    onChange={(value) =>
                                        setDates(value, data.departure_date)
                                    }
                                    min={today}
                                    placeholder="Pick the arrival date"
                                    aria-invalid={!!errors.arrival_date}
                                />
                                <FieldError>{errors.arrival_date}</FieldError>
                            </Field>
                            <div className="flex sm:pt-8">
                                <Badge
                                    variant="secondary"
                                    className="tabular-nums"
                                >
                                    {nights > 0
                                        ? `${nights} ${nights === 1 ? 'night' : 'nights'}`
                                        : '0 nights'}
                                </Badge>
                            </div>
                            <Field data-invalid={!!errors.departure_date}>
                                <FieldLabel htmlFor="book-departure">
                                    Departure
                                </FieldLabel>
                                <DatePicker
                                    id="book-departure"
                                    value={data.departure_date}
                                    onChange={(value) =>
                                        form.setData('departure_date', value)
                                    }
                                    min={
                                        data.arrival_date === ''
                                            ? today
                                            : shiftDate(data.arrival_date, 1)
                                    }
                                    openOn={
                                        data.arrival_date === ''
                                            ? undefined
                                            : shiftDate(data.arrival_date, 1)
                                    }
                                    placeholder="Pick the departure date"
                                    aria-invalid={!!errors.departure_date}
                                />
                                <FieldError>{errors.departure_date}</FieldError>
                            </Field>
                        </div>
                        <div className="grid items-start gap-4 sm:grid-cols-2">
                            <Field data-invalid={!!errors.external_reference}>
                                <FieldLabel htmlFor="book-reference">
                                    Your reference (optional)
                                </FieldLabel>
                                <Input
                                    id="book-reference"
                                    placeholder="e.g. BT-2026-118"
                                    maxLength={100}
                                    autoComplete="off"
                                    value={data.external_reference}
                                    onChange={(event) =>
                                        form.setData(
                                            'external_reference',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={!!errors.external_reference}
                                />
                                <FieldError>
                                    {errors.external_reference}
                                </FieldError>
                            </Field>
                            {slots.length > 1 && (
                                <Field data-invalid={!!errors.group_name}>
                                    <FieldLabel htmlFor="book-group-name">
                                        Group name (optional)
                                    </FieldLabel>
                                    <Input
                                        id="book-group-name"
                                        placeholder="e.g. Tour BT-118"
                                        maxLength={150}
                                        autoComplete="off"
                                        value={data.group_name}
                                        onChange={(event) =>
                                            form.setData(
                                                'group_name',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={!!errors.group_name}
                                    />
                                    <FieldError>{errors.group_name}</FieldError>
                                </Field>
                            )}
                        </div>
                    </FieldGroup>
                </Section>

                <Section
                    title="Rooms and beds"
                    description="Pick a rate first, then a room type it sells. A dorm line sells beds."
                >
                    <div className="flex flex-col gap-3">
                        <div className="overflow-hidden rounded-lg border">
                            <BookLinesHeader />
                            <div className="divide-y">
                                {data.lines.map((line, index) => (
                                    <BookLineRow
                                        key={line.key}
                                        index={index}
                                        line={line}
                                        ratePlans={ratePlans}
                                        roomTypes={roomTypes}
                                        offers={offers.lines?.[index] ?? null}
                                        loading={offers.loading}
                                        errors={errors}
                                        currency={currency}
                                        canRemove={data.lines.length > 1}
                                        onChange={(patch) =>
                                            setLine(index, patch)
                                        }
                                        onRemove={() =>
                                            form.setData(
                                                'lines',
                                                data.lines.filter(
                                                    (_, position) =>
                                                        position !== index,
                                                ),
                                            )
                                        }
                                    />
                                ))}
                            </div>
                            <div className="flex items-center justify-between gap-3 border-t bg-muted/50 px-3 py-2">
                                <span className="text-sm text-muted-foreground">
                                    {slots.length}{' '}
                                    {slots.length === 1 ? 'unit' : 'units'} · up
                                    to {MAX_LINES} lines
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={data.lines.length >= MAX_LINES}
                                    onClick={() =>
                                        form.setData('lines', [
                                            ...data.lines,
                                            newLine(
                                                data.lines.at(-1)
                                                    ?.rate_plan_id ?? onlyPlan,
                                            ),
                                        ])
                                    }
                                >
                                    <Plus data-icon="inline-start" />
                                    Add line
                                </Button>
                            </div>
                        </div>
                        <FieldError>{errors.lines}</FieldError>
                    </div>
                </Section>

                <Section
                    title="Guests"
                    description="One lead guest for each room or bed. The first is the booker."
                >
                    <BookGuestsTable
                        slots={slots}
                        guests={data.guests}
                        idTypes={idTypes}
                        errors={errors}
                        onChange={setGuest}
                    />
                    <FieldError>{errors.guests}</FieldError>
                </Section>
            </div>

            <aside className="h-fit xl:sticky xl:top-4">
                <BookSummary
                    arrival={data.arrival_date}
                    departure={data.departure_date}
                    nights={nights}
                    rows={data.lines.flatMap((line, index) => {
                        const roomType = roomTypeOf(line.room_type_id);

                        if (!roomType) {
                            return [];
                        }

                        const count = unitCount(
                            line,
                            roomType.sellUnit === 'bed',
                        );

                        return [
                            {
                                key: line.key,
                                label:
                                    roomType.sellUnit === 'bed'
                                        ? `${count} ${count === 1 ? 'bed' : 'beds'} · ${roomType.name}`
                                        : `${count} × ${roomType.name}`,
                                amount: quotes[index]?.lineTotal ?? null,
                            },
                        ];
                    })}
                    rooms={rooms}
                    addOns={addOns}
                    total={total}
                    autoAddOns={autoAddOns}
                    companyName={companyName}
                    freeUntil={freeUntil}
                    today={today}
                    guests={slots
                        .map((slot, index) => ({
                            key: slot.key,
                            name: (
                                data.guests[slot.key]?.full_name ?? ''
                            ).trim(),
                            unit: slot.label,
                            isBooker: index === 0,
                        }))
                        .filter((guest) => guest.name !== '')}
                    unnamed={unnamed}
                    currency={currency}
                    canConfirm={canConfirm}
                    processing={form.processing}
                />
            </aside>
        </form>
    );
}
