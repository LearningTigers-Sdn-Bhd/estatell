import { Trash2 } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { BookingQuoteCell } from '@/components/pms/booking-quote-cell';
import { BookingUnitPicker } from '@/components/pms/reservations/booking-unit-picker';
import {
    chargeableQuote,
    isQuotable,
    plansFor,
    resizeAges,
    resizeBeds,
    roomTypeOf,
} from '@/components/pms/reservations/booking-lines';
import type { BookingLine } from '@/components/pms/reservations/booking-lines';
import { useBookingQuote } from '@/components/pms/reservations/use-booking-quote';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

/**
 * The columns of a line from `lg`: room type, rate plan, room or beds, adults, children, rate and remove. The header row in
 * `BookingLinesHeader` uses the same template. Below `lg` the cells stack and show their labels.
 */
export const LINE_COLUMNS =
    'lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1.3fr)_minmax(0,1fr)_4.5rem_4.5rem_minmax(0,12rem)_2rem]';

/** The header of the lines table. Hidden below `lg`, where each cell shows its own label. */
export function BookingLinesHeader() {
    return (
        <div
            className={`hidden gap-3 border-b bg-muted px-3 py-2 text-xs font-medium text-muted-foreground lg:grid ${LINE_COLUMNS}`}
        >
            <span>Room type</span>
            <span>Rate plan</span>
            <span>Room / Bed</span>
            <span>Adults</span>
            <span>Children</span>
            <span className="text-right">Rate</span>
            <span />
        </div>
    );
}

type Options = App.Data.Reservations.AddUnitOptionsData;
type Quote = App.Data.Reservations.BookingQuoteData;
type Errors = Record<string, string | undefined>;

/**
 * One booking line: room type and rate plan, the party, the rooms or beds, and the server's rate. A dorm line asks for
 * the number of beds and one bed for each, and a room line asks for adults, children and their ages.
 */
export function BookingLineRow({
    index,
    line,
    options,
    companyId,
    arrival,
    departure,
    currency,
    errors,
    emptyPickLabel,
    takenIds,
    canRemove,
    onChange,
    onRemove,
    onQuote,
    goLiveDate = null,
    walkIn = false,
}: {
    index: number;
    line: BookingLine;
    options: Options;
    /** The booking's company id as text, or an empty string: it decides which linked plans show. */
    companyId: string;
    arrival: string;
    departure: string;
    currency: App.Data.Property.CurrencyData | null;
    errors: Errors;
    emptyPickLabel: string;
    takenIds: Set<string>;
    canRemove: boolean;
    onChange: (patch: Partial<BookingLine>) => void;
    onRemove: () => void;
    onQuote: (key: string, quote: Quote | null) => void;
    /** Migrated stay only: nights before this date are never charged, so they show no price and leave the totals. */
    goLiveDate?: string | null;
    /** A walk-in: the pickers mark rooms that are not ready. */
    walkIn?: boolean;
}) {
    const roomType = roomTypeOf(line, options.roomTypes);
    const isBed = roomType?.isBed === true;
    const plans = plansFor(options.ratePlans, line.room_type_id, companyId);
    const quotable = isQuotable(line, arrival, departure);
    const { quote: quoted, loading } = useBookingQuote(
        line,
        arrival,
        departure,
        isBed,
        quotable,
    );
    const quote = useMemo(
        () =>
            chargeableQuote(
                quoted,
                goLiveDate,
                isBed ? Number.parseInt(line.beds, 10) || 1 : 1,
            ),
        [quoted, goLiveDate, isBed, line.beds],
    );
    const error = (field: string) => errors[`lines.${index}.${field}`];
    const beds = isBed
        ? resizeBeds(line.bed_ids, Number.parseInt(line.beds, 10) || 1)
        : [];
    const quoteErrors = quote ? Object.values(quote.errors) : [];

    useEffect(() => {
        onQuote(line.key, quote);

        return () => onQuote(line.key, null);
    }, [line.key, quote, onQuote]);

    const bedCount = Number.parseInt(line.beds, 10) || 1;
    const agesError = Object.entries(errors).find(([key]) =>
        key.startsWith(`lines.${index}.child_ages`),
    )?.[1];

    return (
        <div className={`grid gap-3 p-3 ${LINE_COLUMNS}`}>
            <Field data-invalid={!!error('room_type_id')}>
                <FieldLabel
                    htmlFor={`line-${index}-room_type_id`}
                    className="lg:sr-only"
                >
                    Room type
                </FieldLabel>
                <EnumSelect
                    id={`line-${index}-room_type_id`}
                    placeholder="Select room type"
                    options={options.roomTypes.map((type) => ({
                        value: String(type.id),
                        label: type.name,
                    }))}
                    value={line.room_type_id}
                    onValueChange={(value) =>
                        onChange({
                            room_type_id: value,
                            rate_plan_id: '',
                            room_id: '',
                            bed_ids: [],
                            child_ages: [],
                        })
                    }
                    aria-invalid={!!error('room_type_id')}
                />
                <FieldError>{error('room_type_id')}</FieldError>
            </Field>
            <Field data-invalid={!!error('rate_plan_id')}>
                <FieldLabel
                    htmlFor={`line-${index}-rate_plan_id`}
                    className="lg:sr-only"
                >
                    Rate plan
                </FieldLabel>
                <EnumSelect
                    id={`line-${index}-rate_plan_id`}
                    placeholder="Select rate plan"
                    options={plans.map((plan) => ({
                        value: String(plan.id),
                        label: plan.name,
                    }))}
                    value={line.rate_plan_id}
                    onValueChange={(value) => onChange({ rate_plan_id: value })}
                    disabled={line.room_type_id === ''}
                    aria-invalid={!!error('rate_plan_id')}
                />
                <FieldError>{error('rate_plan_id')}</FieldError>
            </Field>

            {isBed ? (
                <>
                    <Field data-invalid={!!error('beds')}>
                        <FieldLabel
                            htmlFor={`line-${index}-beds`}
                            className="lg:sr-only"
                        >
                            Beds
                        </FieldLabel>
                        <Input
                            id={`line-${index}-beds`}
                            type="number"
                            min={1}
                            max={99}
                            placeholder="Beds"
                            aria-label="Number of beds"
                            value={line.beds}
                            onChange={(event) =>
                                onChange({
                                    beds: event.target.value,
                                    bed_ids: resizeBeds(
                                        line.bed_ids,
                                        Number.parseInt(
                                            event.target.value,
                                            10,
                                        ) || 1,
                                    ),
                                })
                            }
                            aria-invalid={!!error('beds')}
                        />
                        <FieldError>{error('beds')}</FieldError>
                    </Field>
                    <div className="max-lg:hidden lg:col-span-2" />
                </>
            ) : (
                <>
                    <Field data-invalid={!!error('room_id')}>
                        <FieldLabel
                            htmlFor={`line-${index}-room_id`}
                            className="lg:sr-only"
                        >
                            Room
                        </FieldLabel>
                        <BookingUnitPicker
                            id={`line-${index}-room_id`}
                            label="Room"
                            roomTypeId={line.room_type_id}
                            arrival={arrival}
                            departure={departure}
                            value={line.room_id}
                            onChange={(value) => onChange({ room_id: value })}
                            emptyLabel={emptyPickLabel}
                            takenIds={takenIds}
                            invalid={!!error('room_id')}
                            walkIn={walkIn}
                        />
                        <FieldError>{error('room_id')}</FieldError>
                    </Field>
                    <Field data-invalid={!!error('adults')}>
                        <FieldLabel
                            htmlFor={`line-${index}-adults`}
                            className="lg:sr-only"
                        >
                            Adults
                        </FieldLabel>
                        <Input
                            id={`line-${index}-adults`}
                            type="number"
                            min={1}
                            max={99}
                            aria-label="Adults"
                            title={
                                roomType
                                    ? `Up to ${roomType.maxAdults}`
                                    : undefined
                            }
                            value={line.adults}
                            onChange={(event) =>
                                onChange({ adults: event.target.value })
                            }
                            aria-invalid={!!error('adults')}
                        />
                        <FieldError>{error('adults')}</FieldError>
                    </Field>
                    <Field>
                        <FieldLabel
                            htmlFor={`line-${index}-children`}
                            className="lg:sr-only"
                        >
                            Children
                        </FieldLabel>
                        <Input
                            id={`line-${index}-children`}
                            type="number"
                            min={0}
                            max={20}
                            aria-label="Children"
                            title={
                                roomType
                                    ? `Up to ${roomType.maxChildren}`
                                    : undefined
                            }
                            value={line.child_ages.length}
                            onChange={(event) =>
                                onChange({
                                    child_ages: resizeAges(
                                        line.child_ages,
                                        Number.parseInt(
                                            event.target.value,
                                            10,
                                        ) || 0,
                                    ),
                                })
                            }
                        />
                    </Field>
                </>
            )}

            <div className="flex items-center justify-between lg:justify-end">
                <BookingQuoteCell
                    quote={quote}
                    goLiveDate={goLiveDate}
                    loading={loading}
                    currency={currency}
                    overrideTotal={line.override_total}
                    overrideReason={line.override_reason}
                    onOverrideChange={onChange}
                    errors={errors}
                    index={index}
                />
            </div>
            <div className="flex items-center justify-end">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Remove this line"
                    disabled={!canRemove}
                    onClick={onRemove}
                >
                    <Trash2 />
                </Button>
            </div>

            {isBed && (
                <div className="grid gap-3 sm:grid-cols-2 lg:col-span-full lg:grid-cols-3">
                    {beds.map((bedId, bed) => (
                        <Field
                            key={bed}
                            data-invalid={!!error(`bed_ids.${bed}`)}
                        >
                            <FieldLabel htmlFor={`line-${index}-bed-${bed}`}>
                                Bed {bed + 1}
                            </FieldLabel>
                            <BookingUnitPicker
                                id={`line-${index}-bed-${bed}`}
                                label={`Bed ${bed + 1}`}
                                roomTypeId={line.room_type_id}
                                arrival={arrival}
                                departure={departure}
                                value={bedId}
                                onChange={(value) =>
                                    onChange({
                                        bed_ids: beds.map(
                                            (current, position) =>
                                                position === bed
                                                    ? value
                                                    : current,
                                        ),
                                    })
                                }
                                emptyLabel={emptyPickLabel}
                                takenIds={takenIds}
                                invalid={!!error(`bed_ids.${bed}`)}
                                walkIn={walkIn}
                            />
                            <FieldError>{error(`bed_ids.${bed}`)}</FieldError>
                        </Field>
                    ))}
                </div>
            )}

            {!isBed && line.child_ages.length > 0 && (
                <Field
                    className="lg:col-span-full"
                    data-invalid={agesError !== undefined}
                >
                    <FieldLabel>Children's ages</FieldLabel>
                    <div className="flex flex-wrap gap-2">
                        {line.child_ages.map((age, child) => (
                            <Input
                                key={child}
                                type="number"
                                min={0}
                                max={17}
                                className="w-20"
                                aria-label={`Age of child ${child + 1}`}
                                value={age}
                                onChange={(event) =>
                                    onChange({
                                        child_ages: line.child_ages.map(
                                            (current, position) =>
                                                position === child
                                                    ? event.target.value
                                                    : current,
                                        ),
                                    })
                                }
                                aria-invalid={
                                    !!error(`child_ages.${child}`) ||
                                    !!error('child_ages')
                                }
                            />
                        ))}
                    </div>
                    <FieldError>{agesError}</FieldError>
                </Field>
            )}

            {quoteErrors.length > 0 && (
                <p className="text-sm text-destructive lg:col-span-full">
                    {quoteErrors.join(' ')}
                </p>
            )}
            {quote &&
                quoteErrors.length === 0 &&
                quote.available < (isBed ? bedCount : 1) && (
                    <p className="text-sm text-destructive lg:col-span-full">
                        {quote.available === 0
                            ? 'No unit is free for these dates.'
                            : `Only ${quote.available} free for these dates.`}
                    </p>
                )}
        </div>
    );
}
