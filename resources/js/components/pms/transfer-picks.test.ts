import { describe, expect, test } from 'vite-plus/test';
import {
    defaultPickupTime,
    NO_TRANSFER,
    routeOf,
    transferOptions,
    transferPayload,
} from './transfer-picks';

const route = (
    id: number,
    direction: 'in' | 'out',
    minutes: number | null = 25,
): App.Data.Transfers.TransferRouteData => ({
    id,
    direction,
    directionLabel: direction === 'in' ? 'Transfer in' : 'Transfer out',
    name: direction === 'in' ? 'Airport to Kopi Lane' : 'Kopi Lane to airport',
    place: 'KK Airport',
    minutes,
    sharedChargeItemId: 1,
    sharedChargeItemName: 'Airport shuttle',
    sharedAmount: '35.00',
    privateChargeItemId: null,
    privateChargeItemName: null,
    privateAmount: null,
    active: true,
});

describe('transferOptions', () => {
    test('lists no transfer, then each route of the direction with each way to charge it', () => {
        const options = transferOptions(
            [route(4, 'in'), route(5, 'out')],
            'in',
            null,
        );

        expect(options.map((option) => option.value)).toEqual([
            '',
            '4:shared',
            '4:private',
            '4:included',
        ]);
        expect(options[1].label).toBe('Airport to Kopi Lane · Shared');
        expect(options[2].description).toBe('No price set: charge by hand');
        expect(options[3].description).toBe('No charge');
    });
});

describe('defaultPickupTime', () => {
    test('a transfer in leaves the drive before the arrival time, across midnight too', () => {
        expect(defaultPickupTime('in', '15:00', 25)).toBe('14:35');
        expect(defaultPickupTime('in', '00:10', 25)).toBe('23:45');
    });

    test('a transfer out, or a route without a drive time, takes the stay time', () => {
        expect(defaultPickupTime('out', '11:00', 25)).toBe('11:00');
        expect(defaultPickupTime('in', '15:00', null)).toBe('15:00');
    });
});

describe('transferPayload', () => {
    test('no transfer sends null', () => {
        expect(transferPayload(NO_TRANSFER)).toBeNull();
    });

    test('a pick sends its route, type, time, flight and bags', () => {
        expect(
            transferPayload({
                value: '4:shared',
                pickup_time: '14:35',
                flight_number: ' ak 6231 ',
                bags: '2',
            }),
        ).toEqual({
            mode: 'shared',
            transfer_route_id: 4,
            pickup_time: '14:35',
            flight_number: 'ak 6231',
            bags: 2,
        });
        expect(
            transferPayload({
                value: '4:included',
                pickup_time: '10:00',
                flight_number: '',
                bags: '',
            }),
        ).toMatchObject({ flight_number: null, bags: null });
    });

    test('routeOf finds the picked route', () => {
        expect(
            routeOf({ ...NO_TRANSFER, value: '5:private' }, [
                route(4, 'in'),
                route(5, 'out'),
            ])?.id,
        ).toBe(5);
    });
});
