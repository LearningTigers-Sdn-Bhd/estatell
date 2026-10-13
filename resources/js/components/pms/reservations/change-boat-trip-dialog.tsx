import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    useFormOptions,
    KEEP_OPTIONS,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { BookingBoatSelect } from '@/components/pms/reservations/booking-boat-select';
import {
    boatTripPayload,
    choiceFromTrip,
    departuresFor,
    isTimed,
    type BoatChoice,
    type BoatDeparture,
    type BoatDirection,
    type BoatTripPayload,
} from '@/components/pms/reservations/boat-trips';
import { useBoatSeats } from '@/components/pms/reservations/use-boat-seats';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { set, clear } from '@/routes/reservations/units/boat-trips';

type Unit = App.Data.Reservations.ReservationUnitData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
    direction: BoatDirection;
    /** The available departures, or undefined while they load on open. */
    departures: BoatDeparture[] | undefined;
};

/**
 * Change one direction of a unit's boat trip from the unit panel. One select picks Not set, an available departure,
 * Charter or Own boat. A charter or own boat takes a time and a note. Not set clears the trip. The server refuses
 * a departure that is no longer available, and the error shows on the select.
 */
export function ChangeBoatTripDialog(props: Props) {
    return <ChangeBoatTripForm key={useFreshOnOpen(props.open)} {...props} />;
}

function ChangeBoatTripForm({
    open,
    onOpenChange,
    reservationId,
    unit,
    direction,
    departures,
}: Props) {
    const trip = direction === 'in' ? unit.boatIn : unit.boatOut;
    const stayTime = direction === 'in' ? unit.arrivalTime : unit.departureTime;
    const date = direction === 'in' ? unit.arrival : unit.departure;
    const [choice, setChoice] = useState<BoatChoice>(() =>
        choiceFromTrip(trip),
    );
    const [time, setTime] = useState(stayTime);
    const { seats, loaded: seatsLoaded } = useBoatSeats(direction, date);
    const form = useForm<BoatTripPayload>({
        mode: 'scheduled',
        boat_departure_id: null,
        time: null,
        note: null,
    });
    const label = direction === 'in' ? 'Boat in' : 'Boat out';

    useFormOptions(open, departures !== undefined, 'boatDepartures');

    const params = { reservation: reservationId, unit: unit.id, direction };
    const submit = () => {
        const options = {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        };
        const payload = boatTripPayload(choice, time);

        if (payload === null) {
            router.delete(clear.url(params), options);

            return;
        }

        form.transform(() => payload);
        form.put(set.url(params), options);
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={`${label} · ${unit.roomType}`}
            description={unit.primaryGuest ?? undefined}
            errors={form.errors}
            onSubmit={submit}
            submitLabel={direction === 'in' ? 'Save boat in' : 'Save boat out'}
            processing={form.processing}
            loading={departures === undefined}
        >
            <BookingBoatSelect
                id={`change-boat-${direction}`}
                label={label}
                value={choice}
                onChange={setChoice}
                departures={departuresFor(departures ?? [], direction)}
                seats={seats}
                seatsLoaded={seatsLoaded}
                bookingSeats={0}
                error={form.errors.boat_departure_id}
            />
            {isTimed(choice) && (
                <Field data-invalid={!!form.errors.time}>
                    <FieldLabel htmlFor={`change-boat-${direction}-time`}>
                        Time
                    </FieldLabel>
                    <Input
                        id={`change-boat-${direction}-time`}
                        type="time"
                        value={time}
                        onChange={(event) => setTime(event.target.value)}
                        aria-invalid={!!form.errors.time}
                    />
                    <FieldError>{form.errors.time}</FieldError>
                </Field>
            )}
        </FormOverlay>
    );
}
