import { BookingBoatSelect } from '@/components/pms/reservations/booking-boat-select';
import {
    departuresFor,
    isTimed,
    type BoatChoice,
    type BoatDeparture,
    type BoatDirection,
} from '@/components/pms/reservations/boat-trips';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

/**
 * One Boat in or Boat out pick for an agent: the departures, Charter and Own boat. A charter or own boat also needs its
 * time. The agent does not see the seats taken on each boat.
 */
export function PortalBoatField({
    id,
    direction,
    departures,
    choice,
    time,
    onChange,
    error,
}: {
    id: string;
    direction: BoatDirection;
    departures: BoatDeparture[];
    choice: BoatChoice;
    time: string;
    onChange: (choice: BoatChoice, time: string) => void;
    error?: string;
}) {
    return (
        <div className="flex flex-col gap-3">
            <BookingBoatSelect
                id={id}
                label={direction === 'in' ? 'Boat in' : 'Boat out'}
                value={choice}
                onChange={(next) => onChange(next, time)}
                departures={departuresFor(departures, direction)}
                seats={{}}
                seatsLoaded={false}
                bookingSeats={0}
                error={error}
            />
            {isTimed(choice) && (
                <Field>
                    <FieldLabel htmlFor={`${id}-time`}>Boat time</FieldLabel>
                    <Input
                        id={`${id}-time`}
                        type="time"
                        value={time}
                        onChange={(event) =>
                            onChange(choice, event.target.value)
                        }
                    />
                    <FieldError />
                </Field>
            )}
        </div>
    );
}
