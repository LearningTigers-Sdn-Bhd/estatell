import { expect, test } from 'vite-plus/test';
import {
    moveBeside,
    orderableIds,
    orderBookingSources,
} from './booking-source-order';

type Row = App.Data.Reservations.BookingSourceRowData;

function row(id: number, name: string, overrides: Partial<Row> = {}): Row {
    return {
        id,
        badge: {
            name,
            type: 'direct',
            icon: 'globe',
            letters: null,
            color: 'gray',
        },
        typeLabel: 'Direct',
        otaChannelId: null,
        otaChannelName: null,
        isSystem: false,
        isActive: true,
        usedCount: 0,
        ...overrides,
    };
}

const rows = [
    row(1, 'Phone'),
    row(2, 'Email', { isActive: false }),
    row(3, 'Company / Travel agent', { isSystem: true, typeLabel: 'Company' }),
    row(4, 'Booking.com', { typeLabel: 'OTA', otaChannelName: 'Booking.com' }),
];

test('active staff rows come first, then inactive, then system rows', () => {
    expect(orderBookingSources(rows, '').map((r) => r.id)).toEqual([
        1, 4, 2, 3,
    ]);
});

test('search matches the name, the type and the OTA channel', () => {
    expect(orderBookingSources(rows, 'phone').map((r) => r.id)).toEqual([1]);
    expect(orderBookingSources(rows, 'ota').map((r) => r.id)).toEqual([4]);
    expect(orderBookingSources(rows, 'company').map((r) => r.id)).toEqual([3]);
});

test('only active staff rows can be moved', () => {
    expect(orderableIds(rows)).toEqual([1, 4]);
    expect(moveBeside([1, 4], 4, 1)).toEqual([4, 1]);
    expect(moveBeside([1, 5, 4], 1, 4)).toEqual([5, 4, 1]);
    expect(moveBeside([1, 4], 1, 9)).toBeNull();
});
