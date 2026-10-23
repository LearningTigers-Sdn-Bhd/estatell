type Room = App.Data.StayView.RoomRowData;
type Stay = App.Data.StayView.StayData;
type Block = App.Data.StayView.BlockData;
type DayUse = App.Data.StayView.DayUseData;

/** A stretch of nights in one row, before it is placed on the date columns. Dates are `Y-m-d`, both ends included. */
export type BarInput = {
    id: string;
    rowKey: string;
    firstNight: string;
    lastNight: string;
    /** The first and last night of the whole stay. A bar cut by the window has an open edge. */
    stayFirstNight?: string;
    stayLastNight?: string;
    /** Draw the bar from the middle of the arrival column to the middle of the departure column. */
    halfCells?: boolean;
};

export type Bar = {
    id: string;
    rowKey: string;
    /** The zero-based column of the first night shown. */
    start: number;
    /** How many columns the bar covers. */
    span: number;
    /** The stay started before the window. */
    openStart: boolean;
    /** The stay goes on after the window. */
    openEnd: boolean;
    /** Columns the left edge moves right: 0.5 when the bar begins at an arrival inside the window. */
    leftShift: number;
    /** Columns the right edge moves right: 0.5 when the bar ends at a departure column inside the window. */
    rightShift: number;
};

/** The last night of a stay: the day before the departure date. */
export function lastNightOf(departureDate: string): string {
    const [year, month, day] = departureDate.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day - 1));

    return date.toISOString().slice(0, 10);
}

/** The key of a day use cell in `dayUseCells`: a row and a date. */
export function dayUseCellKey(rowKey: string, date: string): string {
    return `${rowKey}|${date}`;
}

/** Where an arrival starts: the middle of the cell, or the last quarter when a day use takes the third quarter. */
function arrivalShift(
    input: BarInput,
    from: string,
    dayUseCells: ReadonlySet<string>,
): number {
    if (!input.halfCells || from !== input.firstNight) {
        return 0;
    }

    return dayUseCells.has(dayUseCellKey(input.rowKey, from)) ? 0.75 : 0.5;
}

/**
 * Place bars on the date columns: the column they start in, how many columns they cover, and whether they are cut at
 * the left or right edge. A bar wholly outside the dates is left out. Display only.
 */
export function projectBars(
    inputs: BarInput[],
    dates: string[],
    dayUseCells: ReadonlySet<string> = new Set(),
): Bar[] {
    if (dates.length === 0) {
        return [];
    }

    const first = dates[0];
    const last = dates[dates.length - 1];
    const bars: Bar[] = [];

    for (const input of inputs) {
        if (input.lastNight < first || input.firstNight > last) {
            continue;
        }

        const from = input.firstNight < first ? first : input.firstNight;
        const to = input.lastNight > last ? last : input.lastNight;
        const start = dates.indexOf(from);
        const end = dates.indexOf(to);

        if (start < 0 || end < start) {
            continue;
        }

        bars.push({
            id: input.id,
            rowKey: input.rowKey,
            start,
            span: end - start + 1,
            openStart: from === first && (input.stayFirstNight ?? from) < first,
            openEnd: to === last && (input.stayLastNight ?? to) > last,
            leftShift: arrivalShift(input, from, dayUseCells),
            rightShift:
                input.halfCells && to === input.lastNight && to !== last
                    ? 0.5
                    : 0,
        });
    }

    return bars;
}

/** The left edge and width of a bar, in pixels. */
export function barBox(
    bar: Bar,
    labelWidth: number,
    columnWidth: number,
): { left: number; width: number } {
    return {
        left: labelWidth + (bar.start + bar.leftShift) * columnWidth,
        width: (bar.span - bar.leftShift + bar.rightShift) * columnWidth,
    };
}

/** The segments of the stays as bar inputs. Stays are drawn in half cells, so a departure and an arrival share a day. */
export function stayBarInputs(stays: Stay[]): BarInput[] {
    return stays.flatMap((stay) =>
        stay.segments.map((segment, index) => ({
            id: `stay-${stay.unitId}-${index}`,
            rowKey: segment.rowKey,
            firstNight: segment.firstNight,
            lastNight: segment.lastNight,
            stayFirstNight: stay.arrivalDate,
            stayLastNight: lastNightOf(stay.departureDate),
            halfCells: true,
        })),
    );
}

/**
 * The rows a block takes out of sale. A block on a bed takes that bed. A block on a room takes the room, or every
 * bed of it when the room sells beds.
 */
export function blockRowKeys(block: Block, rooms: Room[]): string[] {
    if (block.bedId !== null) {
        return [`bed-${block.bedId}`];
    }

    return rooms
        .filter((room) => room.roomId === block.roomId)
        .map((room) => room.key);
}

/** The blocks as bar inputs, one for each row they take. */
export function blockBarInputs(blocks: Block[], rooms: Room[]): BarInput[] {
    return blocks.flatMap((block) =>
        blockRowKeys(block, rooms).map((rowKey) => ({
            id: `block-${block.id}-${rowKey}`,
            rowKey,
            firstNight: block.firstNight,
            lastNight: block.lastNight,
        })),
    );
}

/** The rows a day use takes. It takes the room, or every bed of it when the room sells beds. */
function dayUseRowKeys(dayUse: DayUse, rooms: Room[]): string[] {
    return rooms
        .filter((room) => room.roomId === dayUse.roomId)
        .map((room) => room.key);
}

/** The day uses of one row on one date. They share the third quarter of that cell. */
export type DayUseMarker = {
    id: string;
    rowKey: string;
    /** The zero-based column of the date. */
    column: number;
    /** Sorted by start time. */
    dayUses: DayUse[];
};

/** The day uses grouped by row and date, left out when the date is not in the window. Display only. */
export function dayUseMarkers(
    dayUses: DayUse[],
    rooms: Room[],
    dates: string[],
): DayUseMarker[] {
    const markers = new Map<string, DayUseMarker>();

    for (const dayUse of dayUses) {
        const column = dates.indexOf(dayUse.date);

        if (column < 0) {
            continue;
        }

        for (const rowKey of dayUseRowKeys(dayUse, rooms)) {
            const key = dayUseCellKey(rowKey, dayUse.date);
            const marker = markers.get(key) ?? {
                id: `dayuse-${key}`,
                rowKey,
                column,
                dayUses: [],
            };

            marker.dayUses.push(dayUse);
            markers.set(key, marker);
        }
    }

    return [...markers.values()].map((marker) => ({
        ...marker,
        dayUses: marker.dayUses.sort((a, b) =>
            a.startsAt.localeCompare(b.startsAt),
        ),
    }));
}
