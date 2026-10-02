import { Plus, Trash2 } from 'lucide-react';
import { DatePicker } from '@/components/pms/date-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyInput } from '@/components/pms/money-input';
import { roomTypeOf } from '@/components/pms/reservations/booking-lines';
import type {
    BookingAddOn,
    BookingLine,
} from '@/components/pms/reservations/booking-lines';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';

type Options = App.Data.Reservations.AddUnitOptionsData;
type ChargeItem = App.Data.Reservations.ChargeItemOptionData;
type Discount = App.Data.Reservations.DiscountOptionData;
type Errors = Record<string, string | undefined>;

const NIGHTLY = ['per_night', 'per_guest_night'];

function AddOnRow({
    addOn,
    chargeItems,
    arrival,
    departure,
    currency,
    id,
    onChange,
    onRemove,
}: {
    addOn: BookingAddOn;
    chargeItems: ChargeItem[];
    arrival: string;
    departure: string;
    currency: App.Enums.Currency | null;
    id: string;
    onChange: (patch: Partial<BookingAddOn>) => void;
    onRemove: () => void;
}) {
    const item = chargeItems.find(
        (one) => String(one.id) === addOn.charge_item_id,
    );
    const showAmount =
        item !== undefined &&
        (item.allowOverride || item.defaultAmount === null);

    return (
        <div className="grid items-end gap-3 sm:grid-cols-[2fr_1fr_1fr_1fr_auto]">
            <Field>
                <FieldLabel htmlFor={`${id}-item`}>Charge item</FieldLabel>
                <EnumSelect
                    id={`${id}-item`}
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
                />
            </Field>
            {item?.chargingUnit === 'per_item' ? (
                <Field>
                    <FieldLabel htmlFor={`${id}-quantity`}>Quantity</FieldLabel>
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
                <div />
            )}
            {item !== undefined && !NIGHTLY.includes(item.chargingUnit) ? (
                <Field>
                    <FieldLabel htmlFor={`${id}-date`}>Service date</FieldLabel>
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
                <div />
            )}
            {showAmount ? (
                <Field>
                    <FieldLabel htmlFor={`${id}-amount`}>
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
                <div />
            )}
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
    );
}

/**
 * Extras, one block for each line: the add-ons of the line (a dorm line gives every bed the same ones) and a RoomOnly
 * discount. The prices are the server's, shown in the rate cell and the summary.
 */
export function BookingExtrasSection({
    lines,
    options,
    chargeItems,
    discounts,
    arrival,
    departure,
    currency,
    errors,
    onChange,
}: {
    lines: BookingLine[];
    options: Options;
    chargeItems: ChargeItem[];
    discounts: Discount[];
    arrival: string;
    departure: string;
    currency: App.Enums.Currency | null;
    errors: Errors;
    onChange: (index: number, patch: Partial<BookingLine>) => void;
}) {
    return (
        <div className="flex flex-col gap-4">
            {lines.map((line, index) => {
                const roomType = roomTypeOf(line, options.roomTypes);
                const plan = options.ratePlans.find(
                    (one) => String(one.id) === line.rate_plan_id,
                );

                return (
                    <div
                        key={line.key}
                        className="flex flex-col gap-3 rounded-lg border p-3"
                    >
                        <h3 className="text-sm font-medium">
                            {roomType
                                ? `${roomType.name}${plan ? ` · ${plan.name}` : ''}`
                                : `Line ${index + 1}`}
                        </h3>
                        {line.add_ons.map((addOn, position) => (
                            <AddOnRow
                                key={position}
                                id={`extras-${index}-${position}`}
                                addOn={addOn}
                                chargeItems={chargeItems}
                                arrival={arrival}
                                departure={departure}
                                currency={currency}
                                onChange={(patch) =>
                                    onChange(index, {
                                        add_ons: line.add_ons.map(
                                            (current, at) =>
                                                at === position
                                                    ? { ...current, ...patch }
                                                    : current,
                                        ),
                                    })
                                }
                                onRemove={() =>
                                    onChange(index, {
                                        add_ons: line.add_ons.filter(
                                            (_, at) => at !== position,
                                        ),
                                    })
                                }
                            />
                        ))}
                        <FieldError>
                            {errors[`lines.${index}.add_ons`]}
                        </FieldError>
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={chargeItems.length === 0}
                                onClick={() =>
                                    onChange(index, {
                                        add_ons: [
                                            ...line.add_ons,
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
                            {discounts.length > 0 && (
                                <Field
                                    className="w-full sm:w-64"
                                    data-invalid={
                                        !!errors[`lines.${index}.discount_id`]
                                    }
                                >
                                    <FieldLabel
                                        htmlFor={`extras-${index}-discount`}
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
                                            onChange(index, {
                                                discount_id: value,
                                            })
                                        }
                                    />
                                    <FieldDescription>
                                        Applied on the folio, not in the rate.
                                    </FieldDescription>
                                    <FieldError>
                                        {errors[`lines.${index}.discount_id`]}
                                    </FieldError>
                                </Field>
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
