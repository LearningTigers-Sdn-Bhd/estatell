import { blockRowKeys } from './project-bars';

type Room = App.Data.StayView.RoomRowData;
type Stay = App.Data.StayView.StayData;
type Block = App.Data.StayView.BlockData;

/** A stay on a card: what it does in this room on the date. */
export type RoomDayStayState = Extract<
    App.Enums.RoomDayState,
    'departing' | 'in_house' | 'arriving'
>;

export type RoomDayStay = { stay: Stay; state: RoomDayStayState };

/** One card of the Room View: a room or bed with what it does on the date. */
export type RoomDay = {
    room: Room;
    /** Departing, In house, Arriving, Blocked in this order, or only Vacant when nothing else applies. */
    states: App.Enums.RoomDayState[];
    /** The stay lines of the card, Departing first, then In house, then Arriving. */
    stays: RoomDayStay[];
    block: Block | null;
};

export type DaySummary = Record<App.Enums.RoomDayState, number>;

const STAY_ORDER: RoomDayStayState[] = ['departing', 'in_house', 'arriving'];

/**
 * What a stay does in a row on the date, or null when it is not there. A stay holds the row when one of its nights
 * on the date is in it: Arriving on its arrival date, else In house. A stay that leaves on the date holds no night on
 * it, so it is Departing in the row of its last night (the segment that ends last).
 *
 * The stays must reach back one night before the date, as the Room View sends them.
 */
function stayStateIn(
    stay: Stay,
    rowKey: string,
    date: string,
): RoomDayStayState | null {
    const here = stay.segments.filter((segment) => segment.rowKey === rowKey);

    if (
        here.some(
            (segment) =>
                segment.firstNight <= date && date <= segment.lastNight,
        )
    ) {
        return stay.arrivalDate === date ? 'arriving' : 'in_house';
    }

    if (stay.departureDate !== date) {
        return null;
    }

    const lastNight = stay.segments.reduce(
        (latest, segment) =>
            segment.lastNight > latest ? segment.lastNight : latest,
        '',
    );

    return here.some(
        (segment) => segment.lastNight === lastNight && lastNight < date,
    )
        ? 'departing'
        : null;
}

/**
 * One entry for each room or bed, in the order given: its stay lines, its block and its states on the date. A room
 * with a departure and an arrival has both lines and both states. Nothing on the date makes it Vacant. Stays with no
 * room yet are not on any card. Display only: the server decides what is free when it books.
 */
export function roomDays(
    rooms: Room[],
    stays: Stay[],
    blocks: Block[],
    date: string,
): RoomDay[] {
    const blockOf = new Map<string, Block>();

    for (const block of blocks) {
        if (block.firstNight > date || block.lastNight < date) {
            continue;
        }

        for (const key of blockRowKeys(block, rooms)) {
            if (!blockOf.has(key)) {
                blockOf.set(key, block);
            }
        }
    }

    const stayLines = new Map<string, RoomDayStay[]>();

    for (const stay of stays) {
        for (const key of new Set(stay.segments.map((s) => s.rowKey))) {
            const state = stayStateIn(stay, key, date);

            if (state !== null) {
                stayLines.set(key, [
                    ...(stayLines.get(key) ?? []),
                    { stay, state },
                ]);
            }
        }
    }

    return rooms.map((room) => {
        const lines = [...(stayLines.get(room.key) ?? [])].sort(
            (a, b) => STAY_ORDER.indexOf(a.state) - STAY_ORDER.indexOf(b.state),
        );
        const block = blockOf.get(room.key) ?? null;
        const states: App.Enums.RoomDayState[] = [
            ...STAY_ORDER.filter((state) =>
                lines.some((line) => line.state === state),
            ),
            ...(block === null ? [] : (['blocked'] as const)),
        ];

        return {
            room,
            states: states.length === 0 ? ['vacant'] : states,
            stays: lines,
            block,
        };
    });
}

/** How many cards are in each state. A card with two states counts in both. */
export function summarizeDay(days: RoomDay[]): DaySummary {
    const summary: DaySummary = {
        arriving: 0,
        in_house: 0,
        departing: 0,
        vacant: 0,
        blocked: 0,
    };

    for (const day of days) {
        for (const state of day.states) {
            summary[state] += 1;
        }
    }

    return summary;
}

/** The cards that have one of the chosen states. Nothing chosen means all. */
export function showStates(days: RoomDay[], show: string[]): RoomDay[] {
    return show.length === 0
        ? days
        : days.filter((day) =>
              day.states.some((state) => show.includes(state)),
          );
}
