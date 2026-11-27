/**
 * Groups the run sheet's transfers into van runs, for the Vans board. Display only: the server stores each transfer's
 * vehicle, driver and pick-up time, and a run is the transfers that share a vehicle, a pick-up time and a route.
 * See docs/35-transfers/01-land-transfers.md#vans-board.
 */

export type RunTransfer = {
    id: number;
    pickupTime: string;
    routeName: string;
    minutes: number | null;
    pax: number;
    vehicleId: number | null;
    driverId: number | null;
};

export type RunVehicle = { id: number; seats: number | null };

export type TransferRun<T extends RunTransfer> = {
    key: string;
    vehicleId: number;
    pickupTime: string;
    /** `HH:MM` when the route has a travel time, else null. */
    endsAt: string | null;
    routeName: string;
    driverIds: number[];
    transfers: T[];
    pax: number;
    seats: number | null;
    overSeats: boolean;
    /** Keys of the vehicle's other runs that overlap this one in time. */
    clashesWith: string[];
};

const toMinutes = (time: string) => {
    const [hours, minutes] = time.split(':').map(Number);

    return hours * 60 + minutes;
};

const toTime = (minutes: number) => {
    const day = ((minutes % 1440) + 1440) % 1440;

    return `${String(Math.floor(day / 60)).padStart(2, '0')}:${String(day % 60).padStart(2, '0')}`;
};

/**
 * The runs of each vehicle by pick-up time. A run's travel time is the longest route time of its transfers. Two runs of
 * one vehicle clash when one starts before the other ends; a run without a travel time only clashes with a run at the
 * same minute.
 */
export function transferRuns<T extends RunTransfer>(
    transfers: T[],
    vehicles: RunVehicle[],
): Map<number, TransferRun<T>[]> {
    const groups = new Map<string, T[]>();

    for (const transfer of transfers) {
        if (transfer.vehicleId === null) {
            continue;
        }

        const key = `${transfer.vehicleId}|${transfer.pickupTime}|${transfer.routeName}`;
        groups.set(key, [...(groups.get(key) ?? []), transfer]);
    }

    const byVehicle = new Map<number, TransferRun<T>[]>();

    for (const [key, group] of groups) {
        const first = group[0];
        const vehicleId = first.vehicleId as number;
        const seats =
            vehicles.find((vehicle) => vehicle.id === vehicleId)?.seats ?? null;
        const pax = group.reduce((sum, transfer) => sum + transfer.pax, 0);
        const travel = group.reduce<number | null>(
            (longest, transfer) =>
                transfer.minutes === null
                    ? longest
                    : Math.max(longest ?? 0, transfer.minutes),
            null,
        );

        byVehicle.set(vehicleId, [
            ...(byVehicle.get(vehicleId) ?? []),
            {
                key,
                vehicleId,
                pickupTime: first.pickupTime,
                endsAt:
                    travel === null
                        ? null
                        : toTime(toMinutes(first.pickupTime) + travel),
                routeName: first.routeName,
                driverIds: [
                    ...new Set(
                        group
                            .map((transfer) => transfer.driverId)
                            .filter((id): id is number => id !== null),
                    ),
                ],
                transfers: group,
                pax,
                seats,
                overSeats: seats !== null && pax > seats,
                clashesWith: [],
            },
        ]);
    }

    /** Start and end of a run in minutes from midnight; an end past midnight counts on. */
    const span = (run: TransferRun<T>): [number, number] => {
        const begin = toMinutes(run.pickupTime);

        return [
            begin,
            run.endsAt === null
                ? begin
                : begin + ((toMinutes(run.endsAt) - begin + 1440) % 1440),
        ];
    };

    for (const runs of byVehicle.values()) {
        runs.sort((a, b) => a.pickupTime.localeCompare(b.pickupTime));

        for (const run of runs) {
            const [start, end] = span(run);

            run.clashesWith = runs
                .filter((other) => {
                    const [otherStart, otherEnd] = span(other);

                    return (
                        other.key !== run.key &&
                        (otherStart === start ||
                            (otherStart < end && start < otherEnd))
                    );
                })
                .map((other) => other.key);
        }
    }

    return byVehicle;
}
