import { expect, test } from 'vite-plus/test';
import { transferRuns } from './transfer-runs';

const transfer = (
    id: number,
    pickupTime: string,
    attributes: Partial<{
        routeName: string;
        minutes: number | null;
        pax: number;
        vehicleId: number | null;
        driverId: number | null;
    }> = {},
) => ({
    id,
    pickupTime,
    routeName: 'Airport to hotel',
    minutes: 45,
    pax: 2,
    vehicleId: 1,
    driverId: 7,
    ...attributes,
});

test('transfers of one van, time and route make one run, with the pax against the seats', () => {
    const runs = transferRuns(
        [
            transfer(1, '10:00', { pax: 6 }),
            transfer(2, '10:00', { pax: 5 }),
            transfer(3, '10:00', { vehicleId: null }),
        ],
        [{ id: 1, seats: 10 }],
    ).get(1)!;

    expect(runs).toHaveLength(1);
    expect(runs[0].transfers.map((item) => item.id)).toEqual([1, 2]);
    expect(runs[0].pax).toBe(11);
    expect(runs[0].overSeats).toBe(true);
    expect(runs[0].endsAt).toBe('10:45');
    expect(runs[0].driverIds).toEqual([7]);
});

test('runs of one van that overlap in time clash, and runs after the van is back do not', () => {
    const runs = transferRuns(
        [
            transfer(1, '10:00', { minutes: 90 }),
            transfer(2, '11:00', {
                routeName: 'Hotel to airport',
                minutes: 20,
            }),
            transfer(3, '11:30', { routeName: 'Sepilok to Kinabatangan' }),
            transfer(4, '10:00', { vehicleId: 2 }),
        ],
        [
            { id: 1, seats: 10 },
            { id: 2, seats: null },
        ],
    );
    const van = runs.get(1)!;

    expect(van.map((run) => run.pickupTime)).toEqual([
        '10:00',
        '11:00',
        '11:30',
    ]);
    expect(van[0].clashesWith).toEqual([van[1].key]);
    expect(van[1].clashesWith).toEqual([van[0].key]);
    expect(van[2].clashesWith).toEqual([]);
    expect(runs.get(2)![0].overSeats).toBe(false);
});

test('a run with no travel time only clashes with a run at the same minute', () => {
    const van = transferRuns(
        [
            transfer(1, '10:00', { minutes: null }),
            transfer(2, '10:00', { minutes: null, routeName: 'Jetty' }),
            transfer(3, '10:30', { minutes: null, routeName: 'Town' }),
        ],
        [{ id: 1, seats: 10 }],
    ).get(1)!;

    expect(van[0].clashesWith).toEqual([van[1].key]);
    expect(van[2].clashesWith).toEqual([]);
});
