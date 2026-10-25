import { blockRowKeys } from './project-bars';

type Room = App.Data.StayView.RoomRowData;
type Stay = App.Data.StayView.StayData;
type Block = App.Data.StayView.BlockData;

/** The key of a night in `takenNights`: a row and a date. */
function nightKey(rowKey: string, date: string): string {
    return `${rowKey}|${date}`;
}

/**
 * The nights of the loaded dates that a stay or a block already takes, as row and date keys. A night is taken from
 * the arrival to the day before the departure, so a departure leaves its day free for the next arrival.
 */
export function takenNights(
    rooms: Room[],
    stays: Stay[],
    blocks: Block[],
    dates: string[],
): ReadonlySet<string> {
    const taken = new Set<string>();
    const take = (rowKey: string, firstNight: string, lastNight: string) => {
        for (const date of dates) {
            if (date >= firstNight && date <= lastNight) {
                taken.add(nightKey(rowKey, date));
            }
        }
    };

    for (const stay of stays) {
        for (const segment of stay.segments) {
            take(segment.rowKey, segment.firstNight, segment.lastNight);
        }
    }

    for (const block of blocks) {
        for (const rowKey of blockRowKeys(block, rooms)) {
            take(rowKey, block.firstNight, block.lastNight);
        }
    }

    return taken;
}

/**
 * Whether an empty cell starts a booking: the user may manage reservations, the night is not before the business
 * date, and no stay, block or out of service period holds that room or bed that night. Display only; the booking
 * form checks availability again when it saves.
 */
export function isCellBookable({
    canManageReservations,
    rowKey,
    date,
    businessDate,
    taken,
}: {
    canManageReservations: boolean;
    rowKey: string;
    date: string;
    businessDate: string;
    taken: ReadonlySet<string>;
}): boolean {
    return (
        canManageReservations &&
        date >= businessDate &&
        !taken.has(nightKey(rowKey, date))
    );
}
