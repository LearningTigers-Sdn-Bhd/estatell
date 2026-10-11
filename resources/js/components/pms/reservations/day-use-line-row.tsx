import { Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { BookingQuoteCell } from '@/components/pms/reservations/booking-quote-cell';
import { BookingUnitPicker } from '@/components/pms/reservations/booking-unit-picker';
import {
    plansFor,
    resizeAges,
    roomTypeOf,
} from '@/components/pms/reservations/booking-lines';
import type { BookingLine } from '@/components/pms/reservations/booking-lines';
import {
    dayUseRoomTypes,
    endsAt,
    isDayUseQuotable,
    offerOptions,
} from '@/components/pms/reservations/day-use';
import { useBookingQuote } from '@/components/pms/reservations/use-booking-quote';
import { useDayUseOffers } from '@/components/pms/reservations/use-day-use-offers';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

/**
 * The columns of a day use line from `lg`: room type, rate plan, hours, room, adults, children, rate and remove.
 * `DayUseLinesHeader` uses the same template. Below `lg` the cells stack and show their labels.
 */
export const DAY_USE_LINE_COLUMNS =
    'lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1.2fr)_minmax(0,1.3fr)_minmax(0,0.9fr)_4rem_4rem_minmax(0,9rem)_2rem]';

/** The header of the day use lines table. Hidden below `lg`, where each cell shows its own label. */
export function DayUseLinesHeader() {
    return (
        <div
            className={`hidden gap-3 border-b bg-muted px-3 py-2 text-xs font-medium text-muted-foreground lg:grid ${DAY_USE_LINE_COLUMNS}`}
        >
            <span>Room type</span>
            <span>Rate plan</span>
            <span>Hours</span>
            <span>Room</span>
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
 * One day use line: a room type and a day use plan, the hours the plan sells on the date from the start time with their
 * prices, the room (required, free for those hours), the party and the server's rate. There is no price override, no
 * discount and no beds.
 */
export function DayUseLineRow({
    index,
    line,
    options,
    companyId,
    date,
    start,
    currency,
    errors,
    takenIds,
    canRemove,
    onChange,
    onRemove,
    onQuote,
}: {
    index: number;
    line: BookingLine;
    options: Options;
    companyId: string;
    /** The day of the block, `Y-m-d`. */
    date: string;
    /** The start of the block, `H:i`. */
    start: string;
    currency: App.Enums.Currency | null;
    errors: Errors;
    takenIds: Set<string>;
    canRemove: boolean;
    onChange: (patch: Partial<BookingLine>) => void;
    onRemove: () => void;
    onQuote: (key: string, quote: Quote | null) => void;
}) {
    const roomTypes = dayUseRoomTypes(options.roomTypes, options.ratePlans);
    const roomType = roomTypeOf(line, roomTypes);
    const plans = plansFor(
        options.ratePlans,
        line.room_type_id,
        companyId,
        true,
    );
    const { offers, loaded } = useDayUseOffers(
        line.room_type_id,
        line.rate_plan_id,
        date,
        start,
    );
    const quotable = isDayUseQuotable(line, date, start);
    const { quote, loading } = useBookingQuote(
        line,
        date,
        date,
        false,
        quotable,
        start,
    );
    const money = (amount: string) =>
        new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency ?? 'MYR',
        }).format(Number(amount));
    const error = (field: string) => errors[`lines.${index}.${field}`];
    const quoteErrors = quote ? Object.values(quote.errors) : [];
    const ends = endsAt(offers, line.hours);
    const agesError = Object.entries(errors).find(([key]) =>
        key.startsWith(`lines.${index}.child_ages`),
    )?.[1];

    useEffect(() => {
        onQuote(line.key, quote);

        return () => onQuote(line.key, null);
    }, [line.key, quote, onQuote]);

    // An hour count the plan no longer sells for the new date or start is cleared, with the room picked for it.
    useEffect(() => {
        if (
            loaded &&
            line.hours !== '' &&
            !offers.some((offer) => String(offer.hours) === line.hours)
        ) {
            onChange({ hours: '', room_id: '' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [loaded, offers, line.hours]);

    return (
        <div className={`grid gap-3 p-3 ${DAY_USE_LINE_COLUMNS}`}>
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
                    options={roomTypes.map((type) => ({
                        value: String(type.id),
                        label: type.name,
                    }))}
                    value={line.room_type_id}
                    onValueChange={(value) => {
                        const sold = plansFor(
                            options.ratePlans,
                            value,
                            companyId,
                            true,
                        );

                        onChange({
                            room_type_id: value,
                            rate_plan_id:
                                sold.length === 1 ? String(sold[0].id) : '',
                            hours: '',
                            room_id: '',
                            child_ages: [],
                        });
                    }}
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
                    placeholder="Select day use plan"
                    options={plans.map((plan) => ({
                        value: String(plan.id),
                        label: plan.name,
                    }))}
                    value={line.rate_plan_id}
                    onValueChange={(value) =>
                        onChange({
                            rate_plan_id: value,
                            hours: '',
                            room_id: '',
                        })
                    }
                    disabled={line.room_type_id === ''}
                    aria-invalid={!!error('rate_plan_id')}
                />
                <FieldError>{error('rate_plan_id')}</FieldError>
            </Field>
            <Field data-invalid={!!error('hours')}>
                <FieldLabel
                    htmlFor={`line-${index}-hours`}
                    className="lg:sr-only"
                >
                    Hours
                </FieldLabel>
                <EnumSelect
                    id={`line-${index}-hours`}
                    placeholder={
                        loaded && offers.length === 0
                            ? 'No hours sold'
                            : 'Select hours'
                    }
                    options={offerOptions(offers, money)}
                    value={line.hours}
                    onValueChange={(value) =>
                        onChange({ hours: value, room_id: '' })
                    }
                    disabled={offers.length === 0}
                    aria-invalid={!!error('hours')}
                />
                {ends && (
                    <p className="text-xs text-muted-foreground">Ends {ends}</p>
                )}
                <FieldError>{error('hours')}</FieldError>
            </Field>
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
                    arrival={date}
                    departure={date}
                    dayUse={{ start, hours: line.hours }}
                    value={line.room_id}
                    onChange={(value) => onChange({ room_id: value })}
                    emptyLabel="Select room"
                    takenIds={takenIds}
                    invalid={!!error('room_id')}
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
                    title={roomType ? `Up to ${roomType.maxAdults}` : undefined}
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
                        roomType ? `Up to ${roomType.maxChildren}` : undefined
                    }
                    value={line.child_ages.length}
                    onChange={(event) =>
                        onChange({
                            child_ages: resizeAges(
                                line.child_ages,
                                Number.parseInt(event.target.value, 10) || 0,
                            ),
                        })
                    }
                />
            </Field>
            <div className="flex items-center justify-between lg:justify-end">
                <BookingQuoteCell
                    quote={quote}
                    loading={loading}
                    currency={currency}
                    overrideTotal=""
                    overrideReason=""
                    onOverrideChange={onChange}
                    errors={errors}
                    index={index}
                    canOverride={false}
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

            {line.child_ages.length > 0 && (
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
            {quote && quoteErrors.length === 0 && quote.available < 1 && (
                <p className="text-sm text-destructive lg:col-span-full">
                    No room is free for these hours.
                </p>
            )}
        </div>
    );
}
