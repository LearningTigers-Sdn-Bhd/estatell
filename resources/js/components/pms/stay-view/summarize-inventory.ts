import { blockRowKeys } from './project-bars';

type Room = App.Data.StayView.RoomRowData;
type Stay = App.Data.StayView.StayData;
type Block = App.Data.StayView.BlockData;

export type InventoryDay = {
    date: string;
    /** Rows shown: rooms, or beds in a dorm. */
    total: number;
    occupied: number;
    blocked: number;
    /** Rows not occupied and not blocked. */
    available: number;
    /** Occupied as a whole percent of the rows that are not blocked. */
    occupancy: number;
};

function nightsOf(first: string, last: string, dates: string[]): string[] {
    return dates.filter((date) => date >= first && date <= last);
}

/**
 * Availability and occupancy for each date, from the rows, stays and blocks on screen. Display only: the server
 * decides availability when it books.
 */
export function summarizeInventory(
    rooms: Room[],
    stays: Stay[],
    blocks: Block[],
    dates: string[],
): InventoryDay[] {
    const visible = new Set(rooms.map((room) => room.key));
    const occupied = new Set<string>();
    const blocked = new Set<string>();

    for (const stay of stays) {
        for (const segment of stay.segments) {
            if (!visible.has(segment.rowKey)) {
                continue;
            }

            for (const date of nightsOf(
                segment.firstNight,
                segment.lastNight,
                dates,
            )) {
                occupied.add(`${segment.rowKey}|${date}`);
            }
        }
    }

    for (const block of blocks) {
        for (const rowKey of blockRowKeys(block, rooms)) {
            for (const date of nightsOf(
                block.firstNight,
                block.lastNight,
                dates,
            )) {
                blocked.add(`${rowKey}|${date}`);
            }
        }
    }

    return dates.map((date) => {
        let occupiedCount = 0;
        let blockedCount = 0;

        for (const room of rooms) {
            const key = `${room.key}|${date}`;

            if (blocked.has(key)) {
                blockedCount += 1;
            } else if (occupied.has(key)) {
                occupiedCount += 1;
            }
        }

        const sellable = rooms.length - blockedCount;

        return {
            date,
            total: rooms.length,
            occupied: occupiedCount,
            blocked: blockedCount,
            available: sellable - occupiedCount,
            occupancy:
                sellable === 0
                    ? 0
                    : Math.round((occupiedCount / sellable) * 100),
        };
    });
}
