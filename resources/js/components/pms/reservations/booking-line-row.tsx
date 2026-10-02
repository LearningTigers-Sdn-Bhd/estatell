import { Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { BookingQuoteCell } from '@/components/pms/reservations/booking-quote-cell';
import { BookingUnitPicker } from '@/components/pms/reservations/booking-unit-picker';
import {
    isQuotable,
    resizeAges,
    resizeBeds,
    roomTypeOf,
} from '@/components/pms/reservations/booking-lines';
import type { BookingLine } from '@/components/pms/reservations/booking-lines';
import { useBookingQuote } from '@/components/pms/reservations/use-booking-quote';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';

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
}: {
    index: number;
    line: BookingLine;
    options: Options;
    arrival: string;
    departure: string;
    currency: App.Enums.Currency | null;
    errors: Errors;
    emptyPickLabel: string;
    takenIds: Set<string>;
    canRemove: boolean;
    onChange: (patch: Partial<BookingLine>) => void;
    onRemove: () => void;
    onQuote: (key: string, quote: Quote | null) => void;
}) {
    const roomType = roomTypeOf(line, options.roomTypes);
    const isBed = roomType?.isBed === true;
    const plans = options.ratePlans.filter(
        (plan) =>
            line.room_type_id === '' ||
            plan.roomTypeIds.includes(Number(line.room_type_id)),
    );
    const quotable = isQuotable(line, arrival, departure);
    const { quote, loading } = useBookingQuote(
        line,
        arrival,
        departure,
        isBed,
        quotable,
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

    return (
        <div className="flex flex-col gap-3 rounded-lg border p-3">
            <div className="grid gap-3 md:grid-cols-[1fr_1fr_auto]">
                <Field data-invalid={!!error('room_type_id')}>
                    <FieldLabel htmlFor={`line-${index}-room_type_id`}>
                        Room type
                    </FieldLabel>
                    <EnumSelect
                        id={`line-${index}-room_type_id`}
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
                    <FieldLabel htmlFor={`line-${index}-rate_plan_id`}>
                        Rate plan
                    </FieldLabel>
                    <EnumSelect
                        id={`line-${index}-rate_plan_id`}
                        options={plans.map((plan) => ({
                            value: String(plan.id),
                            label: plan.name,
                        }))}
                        value={line.rate_plan_id}
                        onValueChange={(value) =>
                            onChange({ rate_plan_id: value })
                        }
                        disabled={line.room_type_id === ''}
                        aria-invalid={!!error('rate_plan_id')}
                    />
                    <FieldError>{error('rate_plan_id')}</FieldError>
                </Field>
                <div className="flex items-end justify-between gap-1 md:justify-end">
                    <BookingQuoteCell
                        quote={quote}
                        loading={loading}
                        currency={currency}
                        overrideTotal={line.override_total}
                        overrideReason={line.override_reason}
                        onOverrideChange={onChange}
                        errors={errors}
                        index={index}
                    />
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
            </div>

            {isBed ? (
                <div className="flex flex-col gap-3">
                    <Field className="max-w-40" data-invalid={!!error('beds')}>
                        <FieldLabel htmlFor={`line-${index}-beds`}>
                            Beds
                        </FieldLabel>
                        <Input
                            id={`line-${index}-beds`}
                            type="number"
                            min={1}
                            max={99}
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
                    </Field>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {beds.map((bedId, bed) => (
                            <Field
                                key={bed}
                                data-invalid={!!error(`bed_ids.${bed}`)}
                            >
                                <FieldLabel
                                    htmlFor={`line-${index}-bed-${bed}`}
                                >
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
                                />
                                <FieldError>
                                    {error(`bed_ids.${bed}`)}
                                </FieldError>
                            </Field>
                        ))}
                    </div>
                </div>
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Field data-invalid={!!error('adults')}>
                            <FieldLabel htmlFor={`line-${index}-adults`}>
                                Adults
                            </FieldLabel>
                            <Input
                                id={`line-${index}-adults`}
                                type="number"
                                min={1}
                                max={99}
                                value={line.adults}
                                onChange={(event) =>
                                    onChange({ adults: event.target.value })
                                }
                                aria-invalid={!!error('adults')}
                            />
                            {roomType && (
                                <FieldDescription>
                                    Up to {roomType.maxAdults}.
                                </FieldDescription>
                            )}
                            <FieldError>{error('adults')}</FieldError>
                        </Field>
                        <Field>
                            <FieldLabel htmlFor={`line-${index}-children`}>
                                Children
                            </FieldLabel>
                            <Input
                                id={`line-${index}-children`}
                                type="number"
                                min={0}
                                max={20}
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
                            {roomType && (
                                <FieldDescription>
                                    Up to {roomType.maxChildren}.
                                </FieldDescription>
                            )}
                        </Field>
                        <Field data-invalid={!!error('room_id')}>
                            <FieldLabel htmlFor={`line-${index}-room_id`}>
                                Room
                            </FieldLabel>
                            <BookingUnitPicker
                                id={`line-${index}-room_id`}
                                label="Room"
                                roomTypeId={line.room_type_id}
                                arrival={arrival}
                                departure={departure}
                                value={line.room_id}
                                onChange={(value) =>
                                    onChange({ room_id: value })
                                }
                                emptyLabel={emptyPickLabel}
                                takenIds={takenIds}
                                invalid={!!error('room_id')}
                            />
                            <FieldError>{error('room_id')}</FieldError>
                        </Field>
                    </div>
                    {line.child_ages.length > 0 && (
                        <Field
                            data-invalid={Object.keys(errors).some((key) =>
                                key.startsWith(`lines.${index}.child_ages`),
                            )}
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
                            <FieldError>
                                {
                                    Object.entries(errors).find(([key]) =>
                                        key.startsWith(
                                            `lines.${index}.child_ages`,
                                        ),
                                    )?.[1]
                                }
                            </FieldError>
                        </Field>
                    )}
                </div>
            )}

            {quoteErrors.length > 0 && (
                <p className="text-sm text-destructive">
                    {quoteErrors.join(' ')}
                </p>
            )}
            {quote &&
                quoteErrors.length === 0 &&
                quote.available <
                    (isBed ? Number.parseInt(line.beds, 10) || 1 : 1) && (
                    <p className="text-sm text-destructive">
                        {quote.available === 0
                            ? 'No unit is free for these dates.'
                            : `Only ${quote.available} free for these dates.`}
                    </p>
                )}
        </div>
    );
}
