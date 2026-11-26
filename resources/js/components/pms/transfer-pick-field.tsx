import { EnumSelect } from '@/components/pms/enum-select';
import {
    defaultPickupTime,
    NO_TRANSFER,
    routeOf,
    transferOptions,
    type TransferChoice,
} from '@/components/pms/transfer-picks';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';

type Props = {
    id: string;
    direction: 'in' | 'out';
    value: TransferChoice;
    onChange: (choice: TransferChoice) => void;
    routes: App.Data.Transfers.TransferRouteData[];
    currency: App.Data.Property.CurrencyData | null;
    /** The arrival time (in) or departure time (out) of the Stay row, for the first pick-up time. */
    stayTime: string;
    /** More than one room: the transfer is set on each, and a private vehicle is charged for each. Leave out to say nothing. */
    rooms?: number;
    errors: Record<string, string | undefined>;
};

/**
 * The Transfer in or Transfer out pick of a new booking (the booking form and the company portal): route and type in
 * one select, then the pick-up time, flight and bags. It is set on every room of the booking; one room changes on the
 * reservation's Transfers tab.
 * See docs/35-transfers/01-land-transfers.md#booking-form.
 */
export function TransferPickField({
    id,
    direction,
    value,
    onChange,
    routes,
    currency,
    stayTime,
    rooms = 0,
    errors,
}: Props) {
    const label = direction === 'in' ? 'Transfer in' : 'Transfer out';
    const field = direction === 'in' ? 'transfer_in' : 'transfer_out';
    const error =
        errors[field] ??
        errors[`${field}.mode`] ??
        errors[`${field}.transfer_route_id`];

    function pick(next: string) {
        if (next === '') {
            onChange(NO_TRANSFER);

            return;
        }

        const route = routeOf({ ...value, value: next }, routes);
        onChange({
            ...value,
            value: next,
            pickup_time:
                value.pickup_time === ''
                    ? defaultPickupTime(
                          direction,
                          stayTime,
                          route?.minutes ?? null,
                      )
                    : value.pickup_time,
        });
    }

    return (
        <div className="flex flex-col gap-3">
            <Field data-invalid={!!error}>
                <FieldLabel htmlFor={id}>{label}</FieldLabel>
                <EnumSelect
                    id={id}
                    aria-label={label}
                    options={transferOptions(routes, direction, currency)}
                    value={value.value}
                    onValueChange={pick}
                    aria-invalid={!!error}
                />
                {value.value !== '' && rooms > 1 && (
                    <FieldDescription>
                        Set on each of the {rooms} rooms. Change one room on the
                        Transfers tab after saving.
                    </FieldDescription>
                )}
                <FieldError>{error}</FieldError>
            </Field>
            {value.value !== '' && (
                <div className="grid grid-cols-3 gap-3">
                    <Field data-invalid={!!errors[`${field}.pickup_time`]}>
                        <FieldLabel htmlFor={`${id}-time`}>Pick-up</FieldLabel>
                        <Input
                            id={`${id}-time`}
                            type="time"
                            value={value.pickup_time}
                            onChange={(event) =>
                                onChange({
                                    ...value,
                                    pickup_time: event.target.value,
                                })
                            }
                            aria-invalid={!!errors[`${field}.pickup_time`]}
                        />
                        <FieldError>
                            {errors[`${field}.pickup_time`]}
                        </FieldError>
                    </Field>
                    <Field data-invalid={!!errors[`${field}.flight_number`]}>
                        <FieldLabel htmlFor={`${id}-flight`}>Flight</FieldLabel>
                        <Input
                            id={`${id}-flight`}
                            value={value.flight_number}
                            maxLength={10}
                            placeholder="AK 6231"
                            onChange={(event) =>
                                onChange({
                                    ...value,
                                    flight_number: event.target.value,
                                })
                            }
                            aria-invalid={!!errors[`${field}.flight_number`]}
                        />
                        <FieldError>
                            {errors[`${field}.flight_number`]}
                        </FieldError>
                    </Field>
                    <Field data-invalid={!!errors[`${field}.bags`]}>
                        <FieldLabel htmlFor={`${id}-bags`}>Bags</FieldLabel>
                        <Input
                            id={`${id}-bags`}
                            type="number"
                            inputMode="numeric"
                            min={0}
                            max={99}
                            value={value.bags}
                            onChange={(event) =>
                                onChange({ ...value, bags: event.target.value })
                            }
                            aria-invalid={!!errors[`${field}.bags`]}
                        />
                        <FieldError>{errors[`${field}.bags`]}</FieldError>
                    </Field>
                </div>
            )}
        </div>
    );
}
