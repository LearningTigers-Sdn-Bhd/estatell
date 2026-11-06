import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PortalBoatField } from '@/components/pms/portal/portal-boat-fields';
import {
    boatSetPayload,
    choiceFromTrip,
    type BoatChoice,
    type BoatDeparture,
    type BoatSetUnitPayload,
} from '@/components/pms/reservations/boat-trips';
import { Button } from '@/components/ui/button';
import { update } from '@/routes/portal/bookings/boats';

type Unit = App.Data.Boats.BoatSetupUnitData;
type Pick = { choice: BoatChoice; time: string };
type Row = { in: Pick; out: Pick };

/**
 * The agent sets Boat in and Boat out for each room of its booking. Boat in closes at check-in and Boat out at
 * check-out. A fare or charter charge shows on the booking's breakdown after saving. One refusal saves nothing.
 */
export function BookingBoatsDialog({
    open,
    onOpenChange,
    propertyCode,
    reservationId,
    units,
    departures,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    propertyCode: string;
    reservationId: number;
    units: Unit[];
    departures: BoatDeparture[];
}) {
    const [rows, setRows] = useState<Record<number, Row>>(() =>
        Object.fromEntries(
            units.map((unit) => [
                unit.id,
                {
                    in: {
                        choice: choiceFromTrip(unit.boatIn),
                        time: unit.boatIn?.time ?? '',
                    },
                    out: {
                        choice: choiceFromTrip(unit.boatOut),
                        time: unit.boatOut?.time ?? '',
                    },
                },
            ]),
        ),
    );
    const form = useForm<{ units: BoatSetUnitPayload[] }>({ units: [] });
    const first = units[0];

    const copyFirstToAll = () => {
        setRows((current) =>
            Object.fromEntries(
                units.map((unit) => [
                    unit.id,
                    {
                        in: unit.canSetBoatIn
                            ? current[first.id].in
                            : current[unit.id].in,
                        out: unit.canSetBoatOut
                            ? current[first.id].out
                            : current[unit.id].out,
                    },
                ]),
            ),
        );
    };

    const submit = () => {
        form.transform(() => ({
            units: boatSetPayload(
                units.map((unit) => ({
                    unitId: unit.id,
                    in: unit.canSetBoatIn ? rows[unit.id].in.choice : null,
                    out: unit.canSetBoatOut ? rows[unit.id].out.choice : null,
                    stayIn: rows[unit.id].in.time,
                    stayOut: rows[unit.id].out.time,
                })),
            ),
        }));
        form.put(
            update.url({ property: propertyCode, reservation: reservationId }),
            { preserveScroll: true, onSuccess: () => onOpenChange(false) },
        );
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Boats"
            description="A fare or charter charge shows on the breakdown after you save."
            errors={form.errors}
            onSubmit={submit}
            submitLabel="Save boats"
            processing={form.processing}
        >
            {units.length > 1 && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="self-start"
                    onClick={copyFirstToAll}
                >
                    Use the first room's boats for all rooms
                </Button>
            )}
            {units.map((unit) => (
                <section
                    key={unit.id}
                    className="flex flex-col gap-3 border-t pt-4 first:border-t-0 first:pt-0"
                >
                    <h3 className="text-sm font-medium">{unit.label}</h3>
                    {unit.canSetBoatIn ? (
                        <PortalBoatField
                            id={`boat-in-${unit.id}`}
                            direction="in"
                            departures={departures}
                            choice={rows[unit.id].in.choice}
                            time={rows[unit.id].in.time}
                            onChange={(choice, time) =>
                                setRows((current) => ({
                                    ...current,
                                    [unit.id]: {
                                        ...current[unit.id],
                                        in: { choice, time },
                                    },
                                }))
                            }
                        />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Boat in: {unit.boatIn?.summary ?? 'Not set'}
                        </p>
                    )}
                    {unit.canSetBoatOut ? (
                        <PortalBoatField
                            id={`boat-out-${unit.id}`}
                            direction="out"
                            departures={departures}
                            choice={rows[unit.id].out.choice}
                            time={rows[unit.id].out.time}
                            onChange={(choice, time) =>
                                setRows((current) => ({
                                    ...current,
                                    [unit.id]: {
                                        ...current[unit.id],
                                        out: { choice, time },
                                    },
                                }))
                            }
                        />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Boat out: {unit.boatOut?.summary ?? 'Not set'}
                        </p>
                    )}
                </section>
            ))}
        </FormOverlay>
    );
}
