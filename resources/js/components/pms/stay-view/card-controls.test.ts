import { expect, test } from 'vite-plus/test';
import { cardControls } from './card-controls';
import type { RoomDay } from './room-day';

const day = (
    housekeepingStatus: string,
    states: App.Enums.RoomDayState[],
): RoomDay =>
    ({
        room: { housekeepingStatus },
        states,
        stays: [],
        block: null,
    }) as unknown as RoomDay;

const can = {
    canUpdateHousekeeping: true,
    date: '2026-10-14',
    housekeepingDate: '2026-10-14',
};

test('mark clean shows on a dirty card in any state and on any date', () => {
    expect(cardControls(day('dirty', ['vacant']), can).markClean).toBe(true);
    expect(cardControls(day('dirty', ['blocked']), can).markClean).toBe(true);
    expect(
        cardControls(day('dirty', ['departing']), {
            ...can,
            date: '2026-10-20',
        }).markClean,
    ).toBe(true);
    expect(cardControls(day('clean', ['vacant']), can).markClean).toBe(false);
    expect(cardControls(day('inspected', ['in_house']), can).markClean).toBe(
        false,
    );
});

test('do-not-disturb shows only with a guest in house, on the housekeeping date', () => {
    expect(cardControls(day('clean', ['in_house']), can).doNotDisturb).toBe(
        true,
    );
    expect(
        cardControls(day('clean', ['departing', 'arriving']), can).doNotDisturb,
    ).toBe(false);
    expect(cardControls(day('clean', ['vacant']), can).doNotDisturb).toBe(
        false,
    );
    expect(
        cardControls(day('clean', ['in_house']), { ...can, date: '2026-10-15' })
            .doNotDisturb,
    ).toBe(false);
});

test('without housekeeping.update there are no controls', () => {
    expect(
        cardControls(day('dirty', ['in_house']), {
            ...can,
            canUpdateHousekeeping: false,
        }),
    ).toEqual({ markClean: false, doNotDisturb: false });
});
