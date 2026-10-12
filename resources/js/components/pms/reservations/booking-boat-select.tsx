import { EnumSelect } from '@/components/pms/enum-select';
import {
    boatOptions,
    choiceOf,
    isTimed,
    overSeatsMessage,
    type BoatChoice,
    type BoatDeparture,
} from '@/components/pms/reservations/boat-trips';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';

type Props = {
    id: string;
    /** "Boat in" or "Boat out". */
    label: string;
    value: BoatChoice;
    onChange: (choice: BoatChoice) => void;
    /** The available departures of this direction. */
    departures: BoatDeparture[];
    /** Seats taken on each departure for the date, from the server. */
    seats: Record<string, number>;
    seatsLoaded: boolean;
    /** This booking's guests on the date: counted in the browser for the over-seats warning only. */
    bookingSeats: number;
    /** A refusal of this trip from the server, shown under the select. */
    error?: string;
};

/**
 * The Boat in or Boat out pick of the Stay row. It lists the available departures with their seats, Charter and Own
 * boat. A charter or own boat shows a Note. A full boat shows a warning, and the form can still be saved.
 */
export function BookingBoatSelect({
    id,
    label,
    value,
    onChange,
    departures,
    seats,
    seatsLoaded,
    bookingSeats,
    error,
}: Props) {
    const picked = departures.find(
        (departure) => String(departure.id) === value.value,
    );
    const warning =
        picked === undefined
            ? null
            : overSeatsMessage(picked, seatsLoaded ? seats : {}, bookingSeats);

    return (
        <>
            <Field data-invalid={!!error}>
                <FieldLabel htmlFor={id}>{label}</FieldLabel>
                <EnumSelect
                    id={id}
                    aria-label={label}
                    options={boatOptions(departures, seats, seatsLoaded)}
                    value={value.value}
                    onValueChange={(next) =>
                        onChange(choiceOf(next, value.note))
                    }
                    aria-invalid={!!error}
                />
                {warning !== null && (
                    <FieldDescription className="text-warning">
                        {warning}
                    </FieldDescription>
                )}
                <FieldError>{error}</FieldError>
            </Field>
            {isTimed(value) && (
                <Field>
                    <FieldLabel htmlFor={`${id}-note`}>Note</FieldLabel>
                    <Input
                        id={`${id}-note`}
                        value={value.note}
                        maxLength={255}
                        onChange={(event) =>
                            onChange({
                                value: value.value,
                                note: event.target.value,
                            })
                        }
                    />
                </Field>
            )}
        </>
    );
}
