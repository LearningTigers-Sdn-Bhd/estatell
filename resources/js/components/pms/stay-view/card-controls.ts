import type { RoomDay } from './room-day';

export type CardControls = { markClean: boolean; doNotDisturb: boolean };

/**
 * The quick changes a Room View card offers. Mark clean shows on a dirty card in any state, on any date.
 * Do-not-disturb shows only on a card with a guest in house, and only on the housekeeping date, because the server
 * always sets it for that date. Both need `housekeeping.update`. Display only: the server checks again.
 */
export function cardControls(
    day: RoomDay,
    {
        canUpdateHousekeeping,
        date,
        housekeepingDate,
    }: {
        canUpdateHousekeeping: boolean;
        date: string;
        housekeepingDate: string;
    },
): CardControls {
    return {
        markClean:
            canUpdateHousekeeping && day.room.housekeepingStatus === 'dirty',
        doNotDisturb:
            canUpdateHousekeeping &&
            date === housekeepingDate &&
            day.states.includes('in_house'),
    };
}
