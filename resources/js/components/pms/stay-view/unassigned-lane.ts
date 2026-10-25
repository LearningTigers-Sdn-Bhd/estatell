import { stayBarInputs } from './project-bars';

type Room = App.Data.StayView.RoomRowData;
type Stay = App.Data.StayView.StayData;

/** A stretch of nights to place in a lane. Dates are `Y-m-d`, both ends included. */
export type LaneSegment = { id: string; firstNight: string; lastNight: string };

/** The unassigned lane of one room type: its sub-row keys and how many units wait in it. */
export type Lane = {
    roomTypeId: number;
    roomTypeName: string;
    /** `unassigned-{roomTypeId}-{n}`, one for each sub-row, top to bottom. */
    rowKeys: string[];
    units: number;
};

const PREFIX = 'unassigned-';

/** Whether a segment row key is the unassigned row of a room type. */
export function isUnassignedRow(rowKey: string): boolean {
    return rowKey.startsWith(PREFIX);
}

/** The room type of an `unassigned-{roomTypeId}` row key. */
export function unassignedRoomType(rowKey: string): number {
    return Number(rowKey.slice(PREFIX.length));
}

/**
 * Pack the segments of one room type into as few sub-rows as possible so no two bars overlap. Bars are drawn in half
 * cells, so a bar may begin on the day the one before it ends. Returns the zero-based sub-row of each segment id.
 * Segments are placed by first night, each in the first sub-row that is free, which is the fewest rows possible.
 */
export function packUnassigned(segments: LaneSegment[]): Map<string, number> {
    const placed = new Map<string, number>();
    /** The last night of the latest bar in each sub-row. */
    const ends: string[] = [];

    const sorted = [...segments].sort(
        (a, b) =>
            a.firstNight.localeCompare(b.firstNight) ||
            a.lastNight.localeCompare(b.lastNight) ||
            a.id.localeCompare(b.id),
    );

    for (const segment of sorted) {
        let row = ends.findIndex((end) => end < segment.firstNight);

        if (row < 0) {
            row = ends.length;
            ends.push(segment.lastNight);
        } else {
            ends[row] = segment.lastNight;
        }

        placed.set(segment.id, row);
    }

    return placed;
}

/**
 * The lanes to show and where each unassigned bar goes. A lane exists for a room type when a stay has an unassigned
 * segment in it, so the stays must be the ones that pass the filters. Lanes follow the order room types first
 * appear in `rooms`; a type with no room on screen comes last. `subRowOf` maps a bar id to its sub-row key, for
 * display only: the stay keeps its `unassigned-{roomTypeId}` segment.
 */
export function unassignedLanes(
    stays: Stay[],
    rooms: Room[],
): { lanes: Lane[]; subRowOf: Map<string, string> } {
    const names = new Map<number, string>();

    for (const room of rooms) {
        if (!names.has(room.roomTypeId)) {
            names.set(room.roomTypeId, room.roomTypeName);
        }
    }

    const order = [...names.keys()];
    const segments = new Map<number, LaneSegment[]>();
    const units = new Map<number, Set<number>>();

    for (const input of stayBarInputs(stays)) {
        if (!isUnassignedRow(input.rowKey)) {
            continue;
        }

        const roomTypeId = unassignedRoomType(input.rowKey);

        segments.set(roomTypeId, [
            ...(segments.get(roomTypeId) ?? []),
            {
                id: input.id,
                firstNight: input.firstNight,
                lastNight: input.lastNight,
            },
        ]);
        units.set(
            roomTypeId,
            (units.get(roomTypeId) ?? new Set()).add(
                Number(input.id.split('-')[1]),
            ),
        );
    }

    const subRowOf = new Map<string, string>();
    const lanes = [...segments.keys()]
        .sort((a, b) => {
            const first = order.indexOf(a);
            const second = order.indexOf(b);

            return (
                (first < 0 ? order.length : first) -
                    (second < 0 ? order.length : second) || a - b
            );
        })
        .map((roomTypeId): Lane => {
            const packed = packUnassigned(segments.get(roomTypeId) ?? []);
            const rowCount = Math.max(...packed.values()) + 1;

            for (const [id, row] of packed) {
                subRowOf.set(id, `${PREFIX}${roomTypeId}-${row}`);
            }

            return {
                roomTypeId,
                roomTypeName: names.get(roomTypeId) ?? 'Unknown room type',
                rowKeys: Array.from(
                    { length: rowCount },
                    (_, row) => `${PREFIX}${roomTypeId}-${row}`,
                ),
                units: units.get(roomTypeId)?.size ?? 0,
            };
        });

    return { lanes, subRowOf };
}
