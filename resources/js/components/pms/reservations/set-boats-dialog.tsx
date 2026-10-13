import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { BookingBoatSelect } from '@/components/pms/reservations/booking-boat-select';
import {
    boatSetPayload,
    choiceFromTrip,
    departuresFor,
    type BoatChoice,
    type BoatDeparture,
    type BoatSetRow,
    type BoatSetUnitPayload,
} from '@/components/pms/reservations/boat-trips';
import { useBoatSeats } from '@/components/pms/reservations/use-boat-seats';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { setMany } from '@/routes/reservations/boat-trips';

type SetupUnit = App.Data.Boats.BoatSetupUnitData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    /** The open units, or undefined while they load on open. */
    units: SetupUnit[] | undefined;
    /** The available departures, or undefined while they load on open. */
    departures: BoatDeparture[] | undefined;
};

/**
 * Set boats for every open unit of the reservation, in one change. A unit that is checked in has its Boat in read only.
 * One refusal saves nothing, and the error names the unit.
 */
export function SetBoatsDialog(props: Props) {
    const { open, onOpenChange, units, departures } = props;
    const loaded = units !== undefined && departures !== undefined;
    const freshKey = useFreshOnOpen(open);

    useFormOptions(open, loaded, ['boatDepartures', 'boatSetup']);

    if (!loaded) {
        return (
            <FormOverlay
                variant="dialog"
                open={open}
                onOpenChange={onOpenChange}
                title="Set boats"
                errors={{}}
                onSubmit={() => undefined}
                submitLabel="Save boats"
                processing={false}
                submitDisabled
                loading
            >
                <span />
            </FormOverlay>
        );
    }

    return (
        <SetBoatsForm
            key={freshKey}
            {...props}
            units={units}
            departures={departures}
        />
    );
}

function SetBoatsForm({
    open,
    onOpenChange,
    reservationId,
    units,
    departures,
}: Omit<Props, 'units' | 'departures'> & {
    units: SetupUnit[];
    departures: BoatDeparture[];
}) {
    const [choices, setChoices] = useState<
        Record<number, { in: BoatChoice; out: BoatChoice }>
    >(() =>
        Object.fromEntries(
            units.map((unit) => [
                unit.id,
                {
                    in: choiceFromTrip(unit.boatIn),
                    out: choiceFromTrip(unit.boatOut),
                },
            ]),
        ),
    );
    const form = useForm<{ units: BoatSetUnitPayload[] }>({ units: [] });

    const submit = () => {
        const rows: BoatSetRow[] = units.map((unit) => ({
            unitId: unit.id,
            in: unit.canSetBoatIn ? choices[unit.id].in : null,
            out: unit.canSetBoatOut ? choices[unit.id].out : null,
            stayIn: unit.arrivalTime,
            stayOut: unit.departureTime,
        }));

        form.transform(() => ({ units: boatSetPayload(rows) }));
        form.put(setMany.url({ reservation: reservationId }), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Set boats"
            description="Boat in and Boat out for every open unit. Each unit can still differ afterwards."
            errors={form.errors}
            onSubmit={submit}
            submitLabel="Save boats"
            processing={form.processing}
            submitDisabled={units.length === 0}
        >
            {units.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No unit of this reservation can take a boat trip.
                </p>
            )}
            {units.map((unit) => (
                <SetBoatsRow
                    key={unit.id}
                    unit={unit}
                    departures={departures}
                    choice={choices[unit.id]}
                    onChange={(next) =>
                        setChoices((current) => ({
                            ...current,
                            [unit.id]: next,
                        }))
                    }
                />
            ))}
        </FormOverlay>
    );
}

function SetBoatsRow({
    unit,
    departures,
    choice,
    onChange,
}: {
    unit: SetupUnit;
    departures: BoatDeparture[];
    choice: { in: BoatChoice; out: BoatChoice };
    onChange: (next: { in: BoatChoice; out: BoatChoice }) => void;
}) {
    const inSeats = useBoatSeats('in', unit.arrival);
    const outSeats = useBoatSeats('out', unit.departure);

    return (
        <section className="flex flex-col gap-3 border-t pt-4 first:border-t-0 first:pt-0">
            <h3 className="text-sm font-medium">
                {unit.label} · <DateText date={unit.arrival} /> →{' '}
                <DateText date={unit.departure} />
            </h3>
            {unit.canSetBoatIn ? (
                <BookingBoatSelect
                    id={`set-boat-in-${unit.id}`}
                    label="Boat in"
                    value={choice.in}
                    onChange={(next) => onChange({ ...choice, in: next })}
                    departures={departuresFor(departures, 'in')}
                    seats={inSeats.seats}
                    seatsLoaded={inSeats.loaded}
                    bookingSeats={0}
                />
            ) : (
                <p className="text-sm text-muted-foreground">
                    Boat in: {unit.boatIn?.summary ?? 'Not set'} (read only)
                </p>
            )}
            {unit.canSetBoatOut ? (
                <BookingBoatSelect
                    id={`set-boat-out-${unit.id}`}
                    label="Boat out"
                    value={choice.out}
                    onChange={(next) => onChange({ ...choice, out: next })}
                    departures={departuresFor(departures, 'out')}
                    seats={outSeats.seats}
                    seatsLoaded={outSeats.loaded}
                    bookingSeats={0}
                />
            ) : (
                <p className="text-sm text-muted-foreground">
                    Boat out: {unit.boatOut?.summary ?? 'Not set'} (read only)
                </p>
            )}
        </section>
    );
}
