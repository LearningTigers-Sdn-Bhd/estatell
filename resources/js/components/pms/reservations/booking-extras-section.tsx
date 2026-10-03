import { BedDouble, Package, Percent, Plus, Trash2 } from 'lucide-react';
import { DatePicker } from '@/components/pms/date-picker';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyInput } from '@/components/pms/money-input';
import {
    moveAddOn,
    roomTypeOf,
} from '@/components/pms/reservations/booking-lines';
import type {
    BookingAddOn,
    BookingLine,
} from '@/components/pms/reservations/booking-lines';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

type Options = App.Data.Reservations.AddUnitOptionsData;
type ChargeItem = App.Data.Reservations.ChargeItemOptionData;
type Discount = App.Data.Reservations.DiscountOptionData;
type Errors = Record<string, string | undefined>;

const NIGHTLY = ['per_night', 'per_guest_night'];

/** Line, charge item, quantity, service date, amount and remove. */
const ADD_ON_COLUMNS =
    'lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1.6fr)_5rem_minmax(0,1fr)_minmax(0,1fr)_2rem]';
/** Line and discount. */
const DISCOUNT_COLUMNS = 'lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]';

function lineTitle(line: BookingLine, index: number, options: Options): string {
    const roomType = roomTypeOf(line, options.roomTypes);
    const plan = options.ratePlans.find(
        (one) => String(one.id) === line.rate_plan_id,
    );

    return roomType
        ? `${roomType.name}${plan ? ` · ${plan.name}` : ''}`
        : `Line ${index + 1}`;
}

function NoLines() {
    return (
        <EmptyState
            icon={BedDouble}
            title="No room type picked"
            description="Pick a room type in Rooms & guests first."
        />
    );
}

/** A bordered table: a header row from `lg`, the rows, and a footer. */
function ExtrasTable({
    columns,
    heads,
    footer,
    children,
}: {
    columns: string;
    heads: string[];
    footer: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <div className="overflow-hidden rounded-lg border">
            <div
                className={`hidden gap-3 border-b bg-muted px-3 py-2 text-xs font-medium text-muted-foreground lg:grid ${columns}`}
            >
                {heads.map((head, index) => (
                    <span key={index}>{head}</span>
                ))}
            </div>
            <div className="divide-y">{children}</div>
            <div className="flex items-center justify-between gap-3 border-t bg-muted/50 px-3 py-2">
                {footer}
            </div>
        </div>
    );
}

function AddOnRow({
    id,
    addOn,
    lineIndex,
    lines,
    options,
    chargeItems,
    arrival,
    departure,
    currency,
    error,
    onChange,
    onMove,
    onRemove,
}: {
    id: string;
    addOn: BookingAddOn;
    lineIndex: number;
    lines: BookingLine[];
    options: Options;
    chargeItems: ChargeItem[];
    arrival: string;
    departure: string;
    currency: App.Enums.Currency | null;
    error?: string;
    onChange: (patch: Partial<BookingAddOn>) => void;
    onMove: (toLine: number) => void;
    onRemove: () => void;
}) {
    const item = chargeItems.find(
        (one) => String(one.id) === addOn.charge_item_id,
    );
    const showAmount =
        item !== undefined &&
        (item.allowOverride || item.defaultAmount === null);

    return (
        <div className={`grid gap-3 p-3 ${ADD_ON_COLUMNS}`}>
            <Field>
                <FieldLabel htmlFor={`${id}-line`} className="lg:sr-only">
                    Line
                </FieldLabel>
                {lines.length === 1 ? (
                    <p className="flex h-8 items-center text-sm">
                        {lineTitle(lines[0], 0, options)}
                    </p>
                ) : (
                    <EnumSelect
                        id={`${id}-line`}
                        options={lines.map((line, index) => ({
                            value: String(index),
                            label: lineTitle(line, index, options),
                        }))}
                        value={String(lineIndex)}
                        onValueChange={(value) => onMove(Number(value))}
                    />
                )}
            </Field>
            <Field data-invalid={error !== undefined}>
                <FieldLabel htmlFor={`${id}-item`} className="lg:sr-only">
                    Charge item
                </FieldLabel>
                <EnumSelect
                    id={`${id}-item`}
                    placeholder="Select charge item"
                    options={chargeItems.map((one) => ({
                        value: String(one.id),
                        label: `${one.name} · ${one.chargingUnitLabel}`,
                    }))}
                    value={addOn.charge_item_id}
                    onValueChange={(value) => {
                        const next = chargeItems.find(
                            (one) => String(one.id) === value,
                        );

                        onChange({
                            charge_item_id: value,
                            unit_amount: next?.defaultAmount ?? '',
                            quantity: '1',
                            service_date: '',
                        });
                    }}
                    aria-invalid={error !== undefined}
                />
            </Field>
            {item?.chargingUnit === 'per_item' ? (
                <Field>
                    <FieldLabel
                        htmlFor={`${id}-quantity`}
                        className="lg:sr-only"
                    >
                        Quantity
                    </FieldLabel>
                    <Input
                        id={`${id}-quantity`}
                        type="number"
                        min={1}
                        max={999}
                        value={addOn.quantity}
                        onChange={(event) =>
                            onChange({ quantity: event.target.value })
                        }
                    />
                </Field>
            ) : (
                <div className="max-lg:hidden" />
            )}
            {item !== undefined && !NIGHTLY.includes(item.chargingUnit) ? (
                <Field>
                    <FieldLabel htmlFor={`${id}-date`} className="lg:sr-only">
                        Service date
                    </FieldLabel>
                    <DatePicker
                        id={`${id}-date`}
                        value={addOn.service_date}
                        min={arrival || undefined}
                        max={departure || undefined}
                        onChange={(value) => onChange({ service_date: value })}
                        placeholder="At check-in"
                    />
                </Field>
            ) : (
                <div className="max-lg:hidden" />
            )}
            {showAmount ? (
                <Field>
                    <FieldLabel htmlFor={`${id}-amount`} className="lg:sr-only">
                        Unit amount
                    </FieldLabel>
                    <MoneyInput
                        id={`${id}-amount`}
                        value={addOn.unit_amount}
                        onChange={(value) => onChange({ unit_amount: value })}
                        currency={currency}
                    />
                </Field>
            ) : (
                <div className="max-lg:hidden" />
            )}
            <div className="flex items-start justify-end">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Remove this add-on"
                    onClick={onRemove}
                >
                    <Trash2 />
                </Button>
            </div>
            {error !== undefined && (
                <p className="text-sm text-destructive lg:col-span-full">
                    {error}
                </p>
            )}
        </div>
    );
}

/**
 * The Add-ons tab: one flat table for all lines, one row for each add-on, with the line in the first column. The line
 * select moves an add-on to another line. A dorm line gives every bed the same add-ons. The prices are the server's,
 * shown in the rate cell and the summary. The footer adds a row for the first line.
 */
export function BookingAddOnsSection({
    lines,
    options,
    chargeItems,
    arrival,
    departure,
    currency,
    errors,
    onChange,
    onLinesChange,
}: {
    lines: BookingLine[];
    options: Options;
    chargeItems: ChargeItem[];
    arrival: string;
    departure: string;
    currency: App.Enums.Currency | null;
    errors: Errors;
    onChange: (index: number, patch: Partial<BookingLine>) => void;
    onLinesChange: (lines: BookingLine[]) => void;
}) {
    const noRoomType = lines.every((line) => line.room_type_id === '');
    const rows = lines.flatMap((line, lineIndex) =>
        line.add_ons.map((addOn, position) => ({
            addOn,
            lineIndex,
            position,
        })),
    );
    const total = rows.length;

    return (
        <ExtrasTable
            columns={ADD_ON_COLUMNS}
            heads={[
                'Line',
                'Charge item',
                'Quantity',
                'Service date',
                'Amount',
                '',
            ]}
            footer={
                <>
                    <span className="text-sm text-muted-foreground">
                        {total === 0
                            ? 'No add-ons'
                            : `${total} ${total === 1 ? 'add-on' : 'add-ons'}`}
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={noRoomType || chargeItems.length === 0}
                        onClick={() =>
                            onChange(0, {
                                add_ons: [
                                    ...lines[0].add_ons,
                                    {
                                        charge_item_id: '',
                                        quantity: '1',
                                        unit_amount: '',
                                        service_date: '',
                                    },
                                ],
                            })
                        }
                    >
                        <Plus data-icon="inline-start" />
                        Add add-on
                    </Button>
                </>
            }
        >
            {rows.length === 0 &&
                (noRoomType ? (
                    <NoLines />
                ) : (
                    <EmptyState
                        icon={Package}
                        title="No add-ons yet"
                        description={
                            chargeItems.length === 0
                                ? 'This property has no charge items to add.'
                                : 'Use Add add-on to add one.'
                        }
                    />
                ))}
            {rows.map(({ addOn, lineIndex, position }) => (
                <AddOnRow
                    key={`${lines[lineIndex].key}-${position}`}
                    id={`extras-${lineIndex}-${position}`}
                    addOn={addOn}
                    lineIndex={lineIndex}
                    lines={lines}
                    options={options}
                    chargeItems={chargeItems}
                    arrival={arrival}
                    departure={departure}
                    currency={currency}
                    error={
                        position === 0
                            ? errors[`lines.${lineIndex}.add_ons`]
                            : undefined
                    }
                    onChange={(patch) =>
                        onChange(lineIndex, {
                            add_ons: lines[lineIndex].add_ons.map(
                                (current, at) =>
                                    at === position
                                        ? { ...current, ...patch }
                                        : current,
                            ),
                        })
                    }
                    onMove={(to) =>
                        onLinesChange(moveAddOn(lines, lineIndex, position, to))
                    }
                    onRemove={() =>
                        onChange(lineIndex, {
                            add_ons: lines[lineIndex].add_ons.filter(
                                (_, at) => at !== position,
                            ),
                        })
                    }
                />
            ))}
        </ExtrasTable>
    );
}

/**
 * The Discounts tab: one row for each line with a RoomOnly catalogue discount. It is applied on the folio, not in the
 * rate, and the booking stores only the discount id.
 */
export function BookingDiscountsSection({
    lines,
    options,
    discounts,
    errors,
    onChange,
}: {
    lines: BookingLine[];
    options: Options;
    discounts: Discount[];
    errors: Errors;
    onChange: (index: number, patch: Partial<BookingLine>) => void;
}) {
    const noRoomType = lines.every((line) => line.room_type_id === '');

    return (
        <ExtrasTable
            columns={DISCOUNT_COLUMNS}
            heads={['Line', 'Discount']}
            footer={
                <span className="text-sm text-muted-foreground">
                    Applied on the folio, not in the rate.
                </span>
            }
        >
            {noRoomType && <NoLines />}
            {!noRoomType && discounts.length === 0 && (
                <EmptyState
                    icon={Percent}
                    title="No room discounts"
                    description="No room discounts are set up for this property."
                />
            )}
            {!noRoomType &&
                discounts.length > 0 &&
                lines.map((line, index) => (
                    <div
                        key={line.key}
                        className={`grid gap-3 p-3 ${DISCOUNT_COLUMNS}`}
                    >
                        <p className="flex min-h-8 items-center text-sm font-medium">
                            {lineTitle(line, index, options)}
                        </p>
                        <Field
                            data-invalid={
                                !!errors[`lines.${index}.discount_id`]
                            }
                        >
                            <FieldLabel
                                htmlFor={`extras-${index}-discount`}
                                className="lg:sr-only"
                            >
                                Discount
                            </FieldLabel>
                            <EnumSelect
                                id={`extras-${index}-discount`}
                                allLabel="No discount"
                                options={discounts.map((discount) => ({
                                    value: String(discount.id),
                                    label: discount.name,
                                }))}
                                value={line.discount_id}
                                onValueChange={(value) =>
                                    onChange(index, { discount_id: value })
                                }
                                aria-invalid={
                                    !!errors[`lines.${index}.discount_id`]
                                }
                            />
                            <FieldError>
                                {errors[`lines.${index}.discount_id`]}
                            </FieldError>
                        </Field>
                    </div>
                ))}
        </ExtrasTable>
    );
}
