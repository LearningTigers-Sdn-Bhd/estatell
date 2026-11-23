import { Plus, Trash2, TriangleAlert } from 'lucide-react';
import { DatePicker } from '@/components/pms/date-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

type Facility = App.Data.Facilities.FacilityOptionData;
type LineQuote = App.Data.Facilities.FacilityBookingQuoteData;

/** One facility line of the New event page, as the form keeps it. `key` only tells rows apart. */
export type FacilityLine = {
    key: string;
    facility_id: string;
    booking_date: string;
    starts_at: string;
    ends_at: string;
    quantity: string;
    unit_amount: string;
};

export function newFacilityLine(bookingDate: string): FacilityLine {
    return {
        key: Math.random().toString(36).slice(2),
        facility_id: '',
        booking_date: bookingDate,
        starts_at: '',
        ends_at: '',
        quantity: '1',
        unit_amount: '',
    };
}

/** True when the line's facility is charged by hand, so it sends no price. */
export function chargedByHand(line: FacilityLine, facilities: Facility[]) {
    const facility = facilities.find(
        (one) => String(one.id) === line.facility_id,
    );

    return facility !== undefined && facility.listPrice === null;
}

const sessionKey = (startsAt: string, endsAt: string) =>
    `${startsAt}-${endsAt}`;

/**
 * The Facility bookings section of the New event page: one row per line, each with its server quote (price, refusal,
 * Tentative warning, places left). The browser calculates nothing. Errors arrive as `bookings.{index}.{field}`.
 * See docs/33-facilities/03-events-and-event-folios.md#new-event-page.
 */
export function EventFacilityLines({
    lines,
    onChange,
    facilities,
    startsOn,
    endsOn,
    quotes,
    errors,
    currency,
}: {
    lines: FacilityLine[];
    onChange: (lines: FacilityLine[]) => void;
    facilities: Facility[];
    startsOn: string;
    endsOn: string;
    /** The quote of each line, in the same order, or nothing while it loads. */
    quotes: LineQuote[] | null;
    errors: Partial<Record<string, string>>;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const facilityOptions: EnumOption[] = facilities.map((one) => ({
        value: String(one.id),
        label: one.name,
        description: `${one.sharing === 'shared' ? 'Shared' : 'Private'} · ${one.capacity} ${one.capacityUnitLabel}`,
    }));

    const change = (index: number, changes: Partial<FacilityLine>) =>
        onChange(
            lines.map((line, at) =>
                at === index ? { ...line, ...changes } : line,
            ),
        );

    return (
        <div className="flex flex-col gap-3">
            {lines.map((line, index) => {
                const facility = facilities.find(
                    (one) => String(one.id) === line.facility_id,
                );
                const quote = quotes?.[index] ?? null;
                const error = (field: string) =>
                    errors[`bookings.${index}.${field}`];
                const id = (field: string) => `line-${line.key}-${field}`;
                const sessionOptions: EnumOption[] = (
                    facility?.sessions ?? []
                ).map((session) => {
                    const places = quote?.sessions.find(
                        (one) =>
                            one.startsAt === session.startsAt &&
                            one.endsAt === session.endsAt,
                    );

                    return {
                        value: sessionKey(session.startsAt, session.endsAt),
                        label:
                            places === undefined
                                ? `${session.startsAt}–${session.endsAt}`
                                : `${session.startsAt}–${session.endsAt} · ${places.left} left`,
                    };
                });

                return (
                    <div
                        key={line.key}
                        className="flex flex-col gap-3 rounded-lg border p-3"
                    >
                        <div className="grid items-start gap-3 md:grid-cols-[minmax(0,2fr)_minmax(0,1.5fr)_minmax(0,1.8fr)_5rem_minmax(0,1.3fr)_auto]">
                            <Field data-invalid={!!error('facility_id')}>
                                <FieldLabel htmlFor={id('facility')}>
                                    Facility
                                </FieldLabel>
                                <EnumSelect
                                    id={id('facility')}
                                    options={facilityOptions}
                                    value={line.facility_id}
                                    onValueChange={(value) =>
                                        change(index, {
                                            facility_id: value,
                                            starts_at: '',
                                            ends_at: '',
                                            unit_amount:
                                                facilities.find(
                                                    (one) =>
                                                        String(one.id) ===
                                                        value,
                                                )?.listPrice ?? '',
                                        })
                                    }
                                    placeholder="Choose a facility"
                                    aria-invalid={!!error('facility_id')}
                                />
                                <FieldError>{error('facility_id')}</FieldError>
                            </Field>
                            <Field data-invalid={!!error('booking_date')}>
                                <FieldLabel htmlFor={id('date')}>
                                    Date
                                </FieldLabel>
                                <DatePicker
                                    id={id('date')}
                                    value={line.booking_date}
                                    onChange={(value) =>
                                        change(index, { booking_date: value })
                                    }
                                    min={startsOn}
                                    max={endsOn}
                                    placeholder="e.g. 12/12/2026"
                                    aria-invalid={!!error('booking_date')}
                                />
                                <FieldError>{error('booking_date')}</FieldError>
                            </Field>
                            {(facility?.sessions.length ?? 0) > 0 ? (
                                <Field data-invalid={!!error('starts_at')}>
                                    <FieldLabel htmlFor={id('session')}>
                                        Session
                                    </FieldLabel>
                                    <EnumSelect
                                        id={id('session')}
                                        options={sessionOptions}
                                        value={
                                            line.starts_at === ''
                                                ? ''
                                                : sessionKey(
                                                      line.starts_at,
                                                      line.ends_at,
                                                  )
                                        }
                                        onValueChange={(value) => {
                                            const [startsAt, endsAt] =
                                                value.split('-');

                                            change(index, {
                                                starts_at: startsAt,
                                                ends_at: endsAt,
                                            });
                                        }}
                                        placeholder="Choose a session"
                                        aria-invalid={!!error('starts_at')}
                                    />
                                    <FieldError>
                                        {error('starts_at')}
                                    </FieldError>
                                </Field>
                            ) : (
                                <Field
                                    data-invalid={
                                        !!error('starts_at') ||
                                        !!error('ends_at')
                                    }
                                >
                                    <FieldLabel htmlFor={id('from')}>
                                        Time
                                    </FieldLabel>
                                    <div className="flex items-center gap-1">
                                        <Input
                                            id={id('from')}
                                            type="time"
                                            aria-label="From"
                                            value={line.starts_at}
                                            onChange={(event) =>
                                                change(index, {
                                                    starts_at:
                                                        event.target.value,
                                                })
                                            }
                                            aria-invalid={!!error('starts_at')}
                                        />
                                        <span className="text-muted-foreground">
                                            –
                                        </span>
                                        <Input
                                            type="time"
                                            aria-label="To"
                                            value={line.ends_at}
                                            onChange={(event) =>
                                                change(index, {
                                                    ends_at: event.target.value,
                                                })
                                            }
                                            aria-invalid={!!error('ends_at')}
                                        />
                                    </div>
                                    <FieldError>
                                        {error('starts_at') ?? error('ends_at')}
                                    </FieldError>
                                </Field>
                            )}
                            <Field data-invalid={!!error('quantity')}>
                                <FieldLabel htmlFor={id('quantity')}>
                                    Qty
                                </FieldLabel>
                                <Input
                                    id={id('quantity')}
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    placeholder="e.g. 20"
                                    value={line.quantity}
                                    onChange={(event) =>
                                        change(index, {
                                            quantity: event.target.value,
                                        })
                                    }
                                    aria-invalid={!!error('quantity')}
                                />
                                <FieldError>{error('quantity')}</FieldError>
                            </Field>
                            <Field data-invalid={!!error('unit_amount')}>
                                <FieldLabel htmlFor={id('price')}>
                                    {facility
                                        ? `Price · ${facility.rateBasisLabel.toLowerCase()}`
                                        : 'Price'}
                                </FieldLabel>
                                {facility && facility.listPrice === null ? (
                                    <p className="py-2 text-sm text-muted-foreground">
                                        Charge by hand
                                    </p>
                                ) : (
                                    <MoneyInput
                                        id={id('price')}
                                        currency={currency}
                                        value={line.unit_amount}
                                        onChange={(value) =>
                                            change(index, {
                                                unit_amount: value,
                                            })
                                        }
                                        disabled={facility === undefined}
                                        invalid={!!error('unit_amount')}
                                    />
                                )}
                                <FieldError>{error('unit_amount')}</FieldError>
                            </Field>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="md:mt-6"
                                aria-label={`Remove ${facility?.name ?? 'this line'}`}
                                onClick={() =>
                                    onChange(
                                        lines.filter((_, at) => at !== index),
                                    )
                                }
                            >
                                <Trash2 />
                            </Button>
                        </div>
                        {(quote?.amount ||
                            quote?.refusal ||
                            quote?.warning) && (
                            <div
                                className="flex flex-col gap-1 text-sm"
                                aria-live="polite"
                            >
                                {quote.refusal && (
                                    <p className="flex items-center gap-1.5 text-destructive">
                                        <TriangleAlert className="size-4" />
                                        {quote.refusal}
                                    </p>
                                )}
                                {quote.warning && (
                                    <p className="flex items-center gap-1.5 text-muted-foreground">
                                        <TriangleAlert className="size-4" />
                                        {quote.warning}
                                    </p>
                                )}
                                {quote.amount && (
                                    <p className="text-muted-foreground">
                                        <MoneyText
                                            amount={quote.amount}
                                            currency={currency}
                                            plain
                                        />
                                        {quote.taxes.map((tax) => (
                                            <span key={tax.name}>
                                                {tax.inclusive
                                                    ? ' incl. '
                                                    : ' + '}
                                                {tax.name}{' '}
                                                <MoneyText
                                                    amount={tax.amount}
                                                    currency={currency}
                                                    plain
                                                />
                                            </span>
                                        ))}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                );
            })}
            <div>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() =>
                        onChange([...lines, newFacilityLine(startsOn)])
                    }
                >
                    <Plus data-icon="inline-start" />
                    Add facility
                </Button>
            </div>
        </div>
    );
}
