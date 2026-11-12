import { describe, expect, it } from 'vite-plus/test';
import { guestGroups, guestRows } from './booking-guest-rows';

type Booking = App.Data.Portal.PortalBookingData;
type Unit = App.Data.Portal.PortalBookingUnitData;

const guest = (guestId: number, fullName: string) => ({
    guestId,
    fullName,
    phone: null,
    idType: null,
    idTypeLabel: null,
    nationalityCode: null,
});

const unit = (id: number, roomType: string, guestIds: number[]) =>
    ({ id, roomType, guestIds }) as unknown as Unit;

const booking = (units: Unit[], guests: ReturnType<typeof guest>[]) =>
    ({ units, guests }) as unknown as Booking;

describe('booking guest rows', () => {
    it('puts each guest under the unit they stay in, in unit order', () => {
        const rows = guestRows(
            booking(
                [unit(1, 'Deluxe', [10, 11]), unit(2, 'Family', [12])],
                [
                    guest(10, 'Mandy Lim'),
                    guest(11, 'Leanne Lim'),
                    guest(12, 'Tan Wei Ming'),
                ],
            ),
        );

        expect(rows.map((row) => [row.unit, row.guest.fullName])).toEqual([
            ['Deluxe', 'Mandy Lim'],
            ['Deluxe', 'Leanne Lim'],
            ['Family', 'Tan Wei Ming'],
        ]);
    });

    it('lists a booker who stays in no unit last, with no unit', () => {
        const rows = guestRows(
            booking(
                [unit(1, 'Deluxe', [10])],
                [guest(10, 'Mandy Lim'), guest(20, 'Agent Booker')],
            ),
        );

        expect(rows.at(-1)).toMatchObject({
            unit: null,
            guest: { fullName: 'Agent Booker' },
        });
    });
});

describe('booking guest groups', () => {
    it('gives one entry per unit, numbers a shared room type and ends with Not staying', () => {
        const groups = guestGroups(
            booking(
                [
                    unit(1, 'Garden Room', [10]),
                    unit(2, 'Garden Room', []),
                    unit(3, 'Family', [12]),
                ],
                [
                    guest(10, 'Mandy Lim'),
                    guest(12, 'Tan Wei Ming'),
                    guest(20, 'Agent Booker'),
                ],
            ),
        );

        expect(groups).toEqual([
            { key: '1', label: 'Garden Room 1', count: 1 },
            { key: '2', label: 'Garden Room 2', count: 0 },
            { key: '3', label: 'Family', count: 1 },
            { key: 'none', label: 'Not staying', count: 1 },
        ]);
    });
});
