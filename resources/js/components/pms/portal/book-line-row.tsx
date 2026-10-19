import { Trash2, TriangleAlert } from 'lucide-react';
import { BookingQuoteCell } from '@/components/pms/booking-quote-cell';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { resizeAges, unitCount } from '@/components/pms/portal/book-lines';
import type { BookLine } from '@/components/pms/portal/book-lines';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

type RatePlan = App.Data.Portal.PortalRatePlanData;
type RoomType = App.Data.Portal.PortalRoomTypeData;
type LineOffers = App.Data.Portal.PortalLineOffersData;
type Errors = Record<string, string | undefined>;

/**
 * The columns of a line from `lg`: rate plan, room type, rooms, adults, children, rate and remove. `BookLinesHeader`
 * uses the same template. Below `lg` the cells stack and show their labels. It follows the staff lines table.
 */
const LINE_COLUMNS =
    'lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1.3fr)_4.5rem_4.5rem_4.5rem_minmax(0,10rem)_2rem]';

/** The header of the lines table. Hidden below `lg`, where each cell shows its own label. */
export function BookLinesHeader() {
    return (
        <div
            className={`hidden gap-3 border-b bg-muted px-3 py-2 text-xs font-medium text-muted-foreground lg:grid ${LINE_COLUMNS}`}
        >
            <span>Rate plan</span>
            <span>Room type</span>
            <span>Rooms</span>
            <span>Adults</span>
            <span>Children</span>
            <span className="text-right">Rate</span>
            <span />
        </div>
    );
}

/**
 * One line of the book page: the rate plan first, then a room type it sells, the rooms and the guests of each room (or
 * the beds of a dorm), and the server's price. Each room type in the menu shows its price and what is left, or why it
 * cannot be booked.
 */
export function BookLineRow({
    index,
    line,
    ratePlans,
    roomTypes,
    offers,
    loading,
    errors,
    currency,
    canRemove,
    onChange,
    onRemove,
}: {
    index: number;
    line: BookLine;
    ratePlans: RatePlan[];
    roomTypes: RoomType[];
    /** The server's answer for this line, or null before the first one. */
    offers: LineOffers | null;
    loading: boolean;
    errors: Errors;
    currency: App.Data.Property.CurrencyData | null;
    canRemove: boolean;
    onChange: (patch: Partial<BookLine>) => void;
    onRemove: () => void;
}) {
    const plan = ratePlans.find(
        (item) => String(item.id) === line.rate_plan_id,
    );
    const roomType = roomTypes.find(
        (item) => String(item.id) === line.room_type_id,
    );
    const isBed = roomType?.sellUnit === 'bed';
    const offerFor = (roomTypeId: number) =>
        offers?.offers.find((offer) => offer.roomTypeId === roomTypeId);
    const picked = roomType ? offerFor(roomType.id) : undefined;
    const error = (field: string) => errors[`lines.${index}.${field}`];
    const agesError = Object.entries(errors).find(([key]) =>
        key.startsWith(`lines.${index}.child_ages`),
    )?.[1];
    const adults = Number.parseInt(line.adults, 10) || 0;
    const adultsError =
        error('adults') ??
        (roomType && !isBed && adults > roomType.maxAdults
            ? `Up to ${roomType.maxAdults} adults in this room type.`
            : undefined);
    const childrenError =
        roomType && !isBed && line.child_ages.length > roomType.maxChildren
            ? `Up to ${roomType.maxChildren} children in this room type.`
            : undefined;
    const warning =
        offers?.lineError ??
        (adultsError || childrenError ? null : (picked?.reason ?? null));
    const unit = (type: RoomType) => (type.sellUnit === 'bed' ? 'bed' : 'room');

    const roomTypeOptions = (plan?.roomTypeIds ?? [])
        .map((id) => roomTypes.find((type) => type.id === id))
        .filter((type): type is RoomType => type !== undefined)
        .map((type) => {
            const offer = offerFor(type.id);

            return {
                value: String(type.id),
                label: type.name,
                disabled: offer?.reason != null && type.id !== roomType?.id,
                description: offer ? (
                    offer.reason ? (
                        offer.reason
                    ) : (
                        <>
                            {offer.quote?.unitTotal && (
                                <>
                                    <MoneyText
                                        amount={offer.quote.unitTotal}
                                        currency={currency}
                                    />{' '}
                                    / {unit(type)} ·{' '}
                                </>
                            )}
                            {offer.left} {unit(type) === 'bed' ? 'beds ' : ''}
                            left
                        </>
                    )
                ) : undefined,
            };
        });

    return (
        <div className={`grid gap-3 p-3 ${LINE_COLUMNS}`}>
            <Field data-invalid={!!error('rate_plan_id')}>
                <FieldLabel
                    htmlFor={`line-${index}-rate_plan_id`}
                    className="lg:sr-only"
                >
                    Rate plan
                </FieldLabel>
                <EnumSelect
                    id={`line-${index}-rate_plan_id`}
                    placeholder="Choose a rate"
                    options={ratePlans.map((item) => ({
                        value: String(item.id),
                        label: item.name,
                        description: item.summary,
                    }))}
                    value={line.rate_plan_id}
                    onValueChange={(value) => {
                        const next = ratePlans.find(
                            (item) => String(item.id) === value,
                        );

                        onChange({
                            rate_plan_id: value,
                            room_type_id: next?.roomTypeIds.some(
                                (id) => String(id) === line.room_type_id,
                            )
                                ? line.room_type_id
                                : '',
                        });
                    }}
                    aria-invalid={!!error('rate_plan_id')}
                />
                {plan && (
                    <span className="text-xs text-muted-foreground">
                        {plan.summary}
                    </span>
                )}
                <FieldError>{error('rate_plan_id')}</FieldError>
            </Field>

            <Field data-invalid={!!error('room_type_id')}>
                <FieldLabel
                    htmlFor={`line-${index}-room_type_id`}
                    className="lg:sr-only"
                >
                    Room type
                </FieldLabel>
                <EnumSelect
                    id={`line-${index}-room_type_id`}
                    placeholder={
                        plan ? 'Choose a room type' : 'Pick a rate first'
                    }
                    options={roomTypeOptions}
                    value={line.room_type_id}
                    onValueChange={(value) => onChange({ room_type_id: value })}
                    disabled={!plan}
                    aria-invalid={!!error('room_type_id')}
                />
                {roomType && picked && (
                    <span className="text-xs text-muted-foreground">
                        {picked.left} {isBed ? 'beds ' : ''}left
                    </span>
                )}
                <FieldError>{error('room_type_id')}</FieldError>
            </Field>

            {isBed ? (
                <Field
                    data-invalid={!!error('rooms') || !!error('beds')}
                    className="lg:col-span-3"
                >
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
                        placeholder="e.g. 4"
                        aria-label="Number of beds"
                        className="lg:w-24"
                        value={line.beds}
                        onChange={(event) =>
                            onChange({ beds: event.target.value })
                        }
                        aria-invalid={!!error('rooms') || !!error('beds')}
                    />
                    <FieldError>{error('rooms') ?? error('beds')}</FieldError>
                </Field>
            ) : (
                <>
                    <Field data-invalid={!!error('rooms')}>
                        <FieldLabel
                            htmlFor={`line-${index}-rooms`}
                            className="lg:sr-only"
                        >
                            Rooms
                        </FieldLabel>
                        <Input
                            id={`line-${index}-rooms`}
                            type="number"
                            min={1}
                            max={99}
                            placeholder="e.g. 2"
                            aria-label="Rooms"
                            value={line.rooms}
                            onChange={(event) =>
                                onChange({ rooms: event.target.value })
                            }
                            aria-invalid={!!error('rooms')}
                        />
                        <FieldError>{error('rooms')}</FieldError>
                    </Field>
                    <Field data-invalid={!!adultsError}>
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
                            placeholder="e.g. 2"
                            aria-label="Adults per room"
                            title={
                                roomType
                                    ? `Up to ${roomType.maxAdults}`
                                    : undefined
                            }
                            value={line.adults}
                            onChange={(event) =>
                                onChange({ adults: event.target.value })
                            }
                            aria-invalid={!!adultsError}
                        />
                        <FieldError>{adultsError}</FieldError>
                    </Field>
                    <Field
                        data-invalid={!!childrenError || !!error('child_ages')}
                    >
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
                            placeholder="e.g. 1"
                            aria-label="Children per room"
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
                            aria-invalid={
                                !!childrenError || !!error('child_ages')
                            }
                        />
                        <FieldError>{childrenError}</FieldError>
                    </Field>
                </>
            )}

            <div className="flex items-center justify-between lg:justify-end">
                <span className="text-sm text-muted-foreground lg:sr-only">
                    Rate
                    {roomType &&
                        ` · ${unitCount(line, isBed)} ${isBed ? 'beds' : 'rooms'}`}
                </span>
                <BookingQuoteCell
                    quote={
                        picked?.reason === null || picked?.reason === undefined
                            ? (picked?.quote ?? null)
                            : null
                    }
                    loading={loading && roomType !== undefined}
                    currency={currency}
                    overrideTotal=""
                    overrideReason=""
                    onOverrideChange={() => undefined}
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
                    aria-label={`Remove line ${index + 1}`}
                    disabled={!canRemove}
                    onClick={onRemove}
                >
                    <Trash2 />
                </Button>
            </div>

            {!isBed && line.child_ages.length > 0 && (
                <Field
                    className="lg:col-span-full"
                    data-invalid={agesError !== undefined}
                >
                    <FieldLabel>
                        Children&apos;s ages (applies to each room)
                    </FieldLabel>
                    <div className="flex flex-wrap gap-2">
                        {line.child_ages.map((age, child) => (
                            <Input
                                key={child}
                                type="number"
                                min={0}
                                max={17}
                                placeholder="e.g. 6"
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

            {warning && (
                <p className="flex items-center gap-2 text-sm text-destructive lg:col-span-full">
                    <TriangleAlert className="size-4 shrink-0" />
                    {warning}
                </p>
            )}
        </div>
    );
}
