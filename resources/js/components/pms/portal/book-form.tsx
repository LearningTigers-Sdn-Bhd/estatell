import { useForm, useHttp } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { DateText } from '@/components/pms/date-text';
import { FormError } from '@/components/pms/form-error';
import { MoneyText } from '@/components/pms/money-text';
import { BookLineCard } from '@/components/pms/portal/book-line-card';
import {
    MAX_LINES,
    newLine,
    nightsBetween,
    searchLines,
    shiftDate,
    sumAmounts,
} from '@/components/pms/portal/book-lines';
import type { BookLine } from '@/components/pms/portal/book-lines';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { offers, store } from '@/routes/portal/bookings';

type RoomType = App.Data.Portal.PortalRoomTypeData;
type LineOffers = App.Data.Portal.PortalLineOffersData;
type Search = {
    arrival_date: string;
    departure_date: string;
    lines: {
        room_type_id: number;
        adults: number;
        child_ages: (number | null)[];
        beds: number;
    }[];
};
type Result = { key: string; lines: LineOffers[] };

/**
 * The book page. One search prices every line with the property's rates; the company then picks a rate for each line,
 * names the guests and confirms. The server prices and checks everything again on Confirm: a changed price asks for a
 * new search.
 */
export function BookForm({
    propertyCode,
    roomTypes,
    today,
    cancelCutoffDays,
    autoAddOns,
    companyName,
    currency,
}: {
    propertyCode: string;
    roomTypes: RoomType[];
    today: string;
    cancelCutoffDays: number | null;
    autoAddOns: string[];
    companyName: string;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [initial] = useState(() => ({
        arrival_date: '',
        departure_date: '',
        lines: [newLine()] as BookLine[],
        group_name: '',
        external_reference: '',
    }));
    const form = useForm(initial);
    const searcher = useHttp<Search, { lines: LineOffers[] }>({
        arrival_date: '',
        departure_date: '',
        lines: [],
    });
    const [result, setResult] = useState<Result | null>(null);
    const { data } = form;
    const errors = { ...form.errors, ...searcher.errors } as Record<
        string,
        string | undefined
    >;

    const isBed = (roomTypeId: string) =>
        roomTypes.find((type) => String(type.id) === roomTypeId)?.sellUnit ===
        'bed';
    const body: Search = {
        arrival_date: data.arrival_date,
        departure_date: data.departure_date,
        lines: searchLines(data.lines, isBed),
    };
    const key = JSON.stringify(body);
    const fresh = result !== null && result.key === key;
    const nights = nightsBetween(data.arrival_date, data.departure_date);
    const searchable =
        nights > 0 && data.lines.every((line) => line.room_type_id !== '');

    const chosen = data.lines.map((line, index) =>
        fresh
            ? result.lines[index]?.offers.find(
                  (offer) => String(offer.ratePlanId) === line.rate_plan_id,
              )
            : undefined,
    );
    const complete =
        fresh &&
        chosen.every((offer) => offer !== undefined && offer.reason === null) &&
        data.lines.every((line) => line.guest_name.trim() !== '');
    const total = sumAmounts(chosen.map((offer) => offer?.total ?? '0'));
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

    const search = () => {
        form.clearErrors();
        searcher.transform(() => body);
        searcher
            .post(offers.url({ property: propertyCode }), {
                onSuccess: (response) => {
                    setResult({ key, lines: response.lines });
                    form.setData(
                        'lines',
                        data.lines.map((line, index) => {
                            const offer = response.lines[index]?.offers.find(
                                (item) =>
                                    String(item.ratePlanId) ===
                                    line.rate_plan_id,
                            );

                            return offer && offer.reason === null
                                ? line
                                : { ...line, rate_plan_id: '' };
                        }),
                    );
                },
            })
            .catch(() => undefined);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        searcher.clearErrors();
        form.transform((current) => ({
            arrival_date: current.arrival_date,
            departure_date: current.departure_date,
            group_name: current.group_name,
            external_reference: current.external_reference,
            expected_total: total,
            lines: current.lines.map((line) => {
                const bed = isBed(line.room_type_id);

                return {
                    room_type_id: Number(line.room_type_id),
                    adults: bed ? 1 : Number(line.adults),
                    child_ages: bed ? [] : line.child_ages.map(Number),
                    beds: bed ? Number(line.beds) : 1,
                    rate_plan_id: Number(line.rate_plan_id),
                    guest_name: line.guest_name,
                    guest_phone: line.guest_phone,
                };
            }),
        }));
        form.post(store.url({ property: propertyCode }), {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit} className="flex max-w-4xl flex-col gap-6">
            <FormError errors={errors} />

            <FieldGroup className="sm:grid sm:grid-cols-[repeat(2,minmax(0,16rem))_auto] sm:items-end">
                <Field data-invalid={!!errors.arrival_date}>
                    <FieldLabel htmlFor="book-arrival">Arrival</FieldLabel>
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
                <Field data-invalid={!!errors.departure_date}>
                    <FieldLabel htmlFor="book-departure">Departure</FieldLabel>
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
                <p className="text-sm text-muted-foreground tabular-nums">
                    {nights > 0
                        ? `${nights} ${nights === 1 ? 'night' : 'nights'}`
                        : ''}
                </p>
            </FieldGroup>

            <div className="flex flex-col gap-4">
                {data.lines.map((line, index) => (
                    <BookLineCard
                        key={line.key}
                        index={index}
                        line={line}
                        roomTypes={roomTypes}
                        result={fresh ? (result.lines[index] ?? null) : null}
                        errors={errors}
                        currency={currency}
                        canRemove={data.lines.length > 1}
                        onChange={(patch) => setLine(index, patch)}
                        onRemove={() =>
                            form.setData(
                                'lines',
                                data.lines.filter(
                                    (_, position) => position !== index,
                                ),
                            )
                        }
                    />
                ))}
                <FieldError>{errors.lines}</FieldError>
                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={data.lines.length >= MAX_LINES}
                        onClick={() =>
                            form.setData('lines', [...data.lines, newLine()])
                        }
                    >
                        <Plus data-icon="inline-start" />
                        Add line
                    </Button>
                    <span className="text-sm text-muted-foreground">
                        Up to {MAX_LINES} lines.
                    </span>
                    <Button
                        type="button"
                        className="ml-auto"
                        disabled={!searchable || searcher.processing}
                        focusableWhenDisabled
                        onClick={search}
                    >
                        {searcher.processing ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <Search data-icon="inline-start" />
                        )}
                        {fresh ? 'Search again' : 'Search rates'}
                    </Button>
                </div>
                {result !== null && !fresh && (
                    <p className="text-sm text-muted-foreground">
                        The dates or rooms changed. Search again to see the
                        rates.
                    </p>
                )}
            </div>

            {fresh && (
                <>
                    {data.lines.length > 1 && (
                        <Field data-invalid={!!errors.group_name}>
                            <FieldLabel htmlFor="book-group-name">
                                Group name (optional)
                            </FieldLabel>
                            <Input
                                id="book-group-name"
                                name="group_name"
                                placeholder="e.g. Tour BT-118"
                                maxLength={150}
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
                    <Field data-invalid={!!errors.external_reference}>
                        <FieldLabel htmlFor="book-reference">
                            Your reference (optional)
                        </FieldLabel>
                        <Input
                            id="book-reference"
                            name="external_reference"
                            placeholder="e.g. BT-2026-118"
                            maxLength={100}
                            value={data.external_reference}
                            onChange={(event) =>
                                form.setData(
                                    'external_reference',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!errors.external_reference}
                        />
                        <FieldError>{errors.external_reference}</FieldError>
                    </Field>

                    <section className="flex flex-col gap-3 rounded-lg border p-4">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="text-base font-medium">Confirm</h2>
                            <span className="text-lg font-medium">
                                <MoneyText amount={total} currency={currency} />
                            </span>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Billed to {companyName}.
                            {autoAddOns.length > 0 &&
                                ` The total includes ${autoAddOns.join(', ')}.`}
                        </p>
                        {freeUntil !== null && (
                            <p className="text-sm text-muted-foreground">
                                {freeUntil >= today ? (
                                    <>
                                        Free cancellation until{' '}
                                        <DateText date={freeUntil} />.
                                    </>
                                ) : (
                                    'Free cancellation has ended for these dates. You can ask the property to cancel.'
                                )}
                            </p>
                        )}
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                disabled={!complete || form.processing}
                                focusableWhenDisabled
                            >
                                {form.processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Confirm booking
                            </Button>
                        </div>
                    </section>
                </>
            )}
        </form>
    );
}
